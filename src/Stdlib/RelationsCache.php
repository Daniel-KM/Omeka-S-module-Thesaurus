<?php declare(strict_types=1);

namespace Thesaurus\Stdlib;

use Doctrine\DBAL\Connection;

/**
 * Keep the relations of the concepts of a thesaurus to build its tree quickly.
 *
 * Reading the relations "broader" and "narrower" is the main part of the cost
 * of the tree of a big thesaurus, so they are kept in the table
 * "thesaurus_tree_cache", with the visibility of their values. The list of the
 * concepts and their titles are not cached: they are read on each request via
 * the api, so the visibility for the current user is always checked.
 *
 * The cache of a thesaurus is removed when one of its concepts is created,
 * updated or deleted, and by the jobs that convert or index it.
 */
class RelationsCache
{
    /**
     * @var \Doctrine\DBAL\Connection
     */
    protected $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Get the cached relations of a thesaurus.
     *
     * @return array|null The ordered pairs [id, related id, is public] for the
     * keys "broader" and "narrower", or null when they are not cached.
     */
    public function read(int $schemeId): ?array
    {
        $data = $this->connection
            ->executeQuery('SELECT `data` FROM `thesaurus_tree_cache` WHERE `scheme_id` = ?', [$schemeId])
            ->fetchOne();
        if ($data === false) {
            return null;
        }
        $data = json_decode((string) $data, true);
        return is_array($data) && isset($data['broader'], $data['narrower'])
            ? $data
            : null;
    }

    /**
     * Read the relations of the concepts of a thesaurus from the values.
     *
     * The visibility is not checked here, but it is stored with each relation.
     *
     * @param int[] $memberIds All the concepts of the thesaurus.
     * @return array The ordered pairs [id, related id, is public] for the keys
     * "broader" and "narrower".
     */
    public function build(array $memberIds, int $broaderId, int $narrowerId): array
    {
        $members = array_flip(array_map('intval', $memberIds));
        $result = ['broader' => [], 'narrower' => []];
        if (!$members) {
            return $result;
        }
        $sql = <<<'SQL'
            SELECT `id`, `resource_id`, `value_resource_id`, `is_public`
            FROM `value`
            WHERE `property_id` = :property_id
                AND `resource_id` IN (:ids)
                AND `value_resource_id` IS NOT NULL
            SQL;
        foreach (['broader' => $broaderId, 'narrower' => $narrowerId] as $key => $propertyId) {
            // The values are read by chunk, so they are ordered at the end.
            $pairs = [];
            foreach (array_chunk(array_keys($members), 5000) as $ids) {
                $rows = $this->connection
                    ->executeQuery($sql, ['property_id' => $propertyId, 'ids' => $ids], ['property_id' => \PDO::PARAM_INT, 'ids' => Connection::PARAM_INT_ARRAY])
                    ->fetchAllNumeric();
                foreach ($rows as [$valueId, $id, $related, $isPublic]) {
                    if (isset($members[(int) $related])) {
                        $pairs[(int) $valueId] = [(int) $id, (int) $related, (int) $isPublic];
                    }
                }
            }
            ksort($pairs);
            $result[$key] = array_values($pairs);
        }
        return $result;
    }

    /**
     * Store the relations of a thesaurus.
     */
    public function write(int $schemeId, array $data): void
    {
        $this->connection->delete('thesaurus_tree_cache', ['scheme_id' => $schemeId]);
        $this->connection->insert('thesaurus_tree_cache', [
            'scheme_id' => $schemeId,
            'created' => (new \DateTime())->format('Y-m-d H:i:s'),
            'data' => json_encode($data),
        ]);
    }

    /**
     * Remove the cached relations of some thesaurus, or of all of them.
     *
     * @param int[]|null $schemeIds
     */
    public function clear(?array $schemeIds = null): void
    {
        if ($schemeIds === null) {
            $this->connection->executeStatement('DELETE FROM `thesaurus_tree_cache`');
            return;
        }
        $schemeIds = array_values(array_unique(array_filter(array_map('intval', $schemeIds))));
        if ($schemeIds) {
            $this->connection->executeStatement(
                'DELETE FROM `thesaurus_tree_cache` WHERE `scheme_id` IN (:ids)',
                ['ids' => $schemeIds],
                ['ids' => Connection::PARAM_INT_ARRAY]
            );
        }
    }

    /**
     * Get the thesaurus that a resource belongs to, as concept or as scheme.
     *
     * @param int[] $resourceIds
     * @param int[] $schemePropertyIds Properties "skos:inScheme" and
     * "skos:topConceptOf".
     * @return int[]
     */
    public function schemeIdsOf(array $resourceIds, array $schemePropertyIds): array
    {
        $resourceIds = array_values(array_unique(array_filter(array_map('intval', $resourceIds))));
        if (!$resourceIds) {
            return [];
        }
        // A scheme itself is included, since its top concepts may be updated.
        $result = $resourceIds;
        $result = array_merge($result, $this->connection
            ->executeQuery(
                'SELECT `scheme_id` FROM `concept` WHERE `id` IN (:ids)',
                ['ids' => $resourceIds],
                ['ids' => Connection::PARAM_INT_ARRAY]
            )
            ->fetchFirstColumn());
        $schemePropertyIds = array_values(array_filter(array_map('intval', $schemePropertyIds)));
        if ($schemePropertyIds) {
            $result = array_merge($result, $this->connection
                ->executeQuery(
                    'SELECT `value_resource_id` FROM `value` WHERE `resource_id` IN (:ids) AND `property_id` IN (:property_ids) AND `value_resource_id` IS NOT NULL',
                    ['ids' => $resourceIds, 'property_ids' => $schemePropertyIds],
                    ['ids' => Connection::PARAM_INT_ARRAY, 'property_ids' => Connection::PARAM_INT_ARRAY]
                )
                ->fetchFirstColumn());
        }
        return array_values(array_unique(array_map('intval', $result)));
    }
}
