<?php declare(strict_types=1);

namespace Thesaurus\Stdlib;

use Doctrine\DBAL\Connection;

/**
 * Get the ids of the descendants or the ascendants of a concept quickly.
 *
 * The thesaurus is not built: the hierarchy is read level by level, with one
 * query by level, so the cost depends on the number of results, not on the
 * size of the thesaurus. A converted thesaurus uses the index of the table
 * "concept"; a thesaurus made of items uses the skos values "broader" and
 * "narrower", in both directions, since a partial import may set only one.
 *
 * The queries are not recursive, so they work with all versions of MariaDB
 * and MySQL.
 */
class ConceptHierarchy
{
    /**
     * Maximum levels, in order to avoid infinite loops on wrong data.
     *
     * @var int
     */
    const MAX_LEVELS = 100;

    /**
     * @var \Doctrine\DBAL\Connection
     */
    protected $connection;

    /**
     * @var int
     */
    protected $broaderId;

    /**
     * @var int
     */
    protected $narrowerId;

    public function __construct(Connection $connection, int $broaderId, int $narrowerId)
    {
        $this->connection = $connection;
        $this->broaderId = $broaderId;
        $this->narrowerId = $narrowerId;
    }

    /**
     * Get the ids of the descendants of a concept.
     *
     * @return int[]
     */
    public function descendantIds(int $id, bool $self = false): array
    {
        $result = $this->walk($id, $this->isConcept($id) ? 'conceptChildren' : 'itemChildren');
        return $self ? array_merge([$id], $result) : $result;
    }

    /**
     * Get the ids of the ascendants of a concept, from the closest to the top.
     *
     * @return int[]
     */
    public function ascendantIds(int $id, bool $self = false): array
    {
        $result = $this->walk($id, $this->isConcept($id) ? 'conceptParents' : 'itemParents');
        return $self ? array_merge([$id], $result) : $result;
    }

    /**
     * Walk the hierarchy level by level.
     *
     * @return int[]
     */
    protected function walk(int $id, string $method): array
    {
        $result = [];
        $seen = [$id => true];
        $current = [$id];
        for ($level = 0; $current && $level < self::MAX_LEVELS; ++$level) {
            $next = [];
            foreach ($this->$method($current) as $relatedId) {
                if (!isset($seen[$relatedId])) {
                    $seen[$relatedId] = true;
                    $next[] = $relatedId;
                    $result[] = $relatedId;
                }
            }
            $current = $next;
        }
        return $result;
    }

    protected function isConcept(int $id): bool
    {
        return (bool) $this->connection
            ->executeQuery('SELECT 1 FROM `concept` WHERE `id` = ?', [$id])
            ->fetchOne();
    }

    /**
     * @param int[] $ids
     * @return int[]
     */
    protected function conceptChildren(array $ids): array
    {
        return $this->ids(
            'SELECT `id` FROM `concept` WHERE `broader_id` IN (:ids) ORDER BY `position`, `id`',
            $ids
        );
    }

    /**
     * @param int[] $ids
     * @return int[]
     */
    protected function conceptParents(array $ids): array
    {
        return $this->ids(
            'SELECT `broader_id` FROM `concept` WHERE `id` IN (:ids) AND `broader_id` IS NOT NULL',
            $ids
        );
    }

    /**
     * @param int[] $ids
     * @return int[]
     */
    protected function itemChildren(array $ids): array
    {
        $sql = <<<'SQL'
            SELECT `value_resource_id` FROM `value`
            WHERE `property_id` = :narrower AND `resource_id` IN (:ids) AND `value_resource_id` IS NOT NULL
            UNION
            SELECT `resource_id` FROM `value`
            WHERE `property_id` = :broader AND `value_resource_id` IN (:ids)
            SQL;
        return $this->ids($sql, $ids);
    }

    /**
     * @param int[] $ids
     * @return int[]
     */
    protected function itemParents(array $ids): array
    {
        $sql = <<<'SQL'
            SELECT `value_resource_id` FROM `value`
            WHERE `property_id` = :broader AND `resource_id` IN (:ids) AND `value_resource_id` IS NOT NULL
            UNION
            SELECT `resource_id` FROM `value`
            WHERE `property_id` = :narrower AND `value_resource_id` IN (:ids)
            SQL;
        return $this->ids($sql, $ids);
    }

    /**
     * @param int[] $ids
     * @return int[]
     */
    protected function ids(string $sql, array $ids): array
    {
        $params = ['ids' => $ids];
        $types = ['ids' => Connection::PARAM_INT_ARRAY];
        if (strpos($sql, ':broader') !== false) {
            $params['broader'] = $this->broaderId;
            $types['broader'] = \PDO::PARAM_INT;
        }
        if (strpos($sql, ':narrower') !== false) {
            $params['narrower'] = $this->narrowerId;
            $types['narrower'] = \PDO::PARAM_INT;
        }
        return array_map('intval', $this->connection->executeQuery($sql, $params, $types)->fetchFirstColumn());
    }
}
