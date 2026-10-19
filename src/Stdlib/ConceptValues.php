<?php declare(strict_types=1);

namespace Thesaurus\Stdlib;

use Doctrine\DBAL\Connection;

/**
 * Fix the data types of the values that point to a concept.
 *
 * A custom vocab contains items, so a value filled with a concept via the
 * custom vocab of a thesaurus is no longer valid once the concepts are a
 * dedicated resource type: it uses the data type of the thesaurus, that manages
 * the concepts.
 */
class ConceptValues
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
     * Set the data type of the thesaurus to the values of its concepts.
     *
     * @param int[]|null $schemeIds Limit to the concepts of these thesaurus.
     * @return int The number of updated values.
     */
    public function retypeCustomVocabValues(?array $schemeIds = null): int
    {
        $sql = <<<'SQL'
            UPDATE `value`
            INNER JOIN `concept` ON `concept`.`id` = `value`.`value_resource_id`
            SET `value`.`type` = CONCAT('thesaurus:', `concept`.`scheme_id`)
            WHERE `value`.`type` LIKE 'customvocab:%'
            SQL;
        return (int) $this->connection->executeStatement(...$this->scope($sql, $schemeIds));
    }

    /**
     * @param int[]|null $schemeIds Limit to the concepts of these thesaurus.
     * @return int The number of values to update.
     */
    public function countCustomVocabValues(?array $schemeIds = null): int
    {
        $sql = <<<'SQL'
            SELECT COUNT(*)
            FROM `value`
            INNER JOIN `concept` ON `concept`.`id` = `value`.`value_resource_id`
            WHERE `value`.`type` LIKE 'customvocab:%'
            SQL;
        return (int) $this->connection->executeQuery(...$this->scope($sql, $schemeIds))->fetchOne();
    }

    /**
     * Get the thesaurus with values to update, as ids and labels.
     *
     * @return array Labels of the thesaurus by id.
     */
    public function schemesWithCustomVocabValues(): array
    {
        $sql = <<<'SQL'
            SELECT `concept`.`scheme_id`, `resource`.`title`
            FROM `value`
            INNER JOIN `concept` ON `concept`.`id` = `value`.`value_resource_id`
            INNER JOIN `resource` ON `resource`.`id` = `concept`.`scheme_id`
            WHERE `value`.`type` LIKE 'customvocab:%'
            GROUP BY `concept`.`scheme_id`, `resource`.`title`
            ORDER BY `resource`.`title`
            SQL;
        return $this->connection->executeQuery($sql)->fetchAllKeyValue();
    }

    /**
     * Export the data types of the values that point to some resources.
     *
     * The conversion of the items into concepts changes the data type of these
     * values ("customvocab:N" and "resource:item" become "thesaurus:N" and
     * "resource:concept"), so the original types are saved to be able to revert
     * it exactly.
     *
     * @param int[] $resourceIds Ids of the concepts or of the items.
     * @return array List of ["id" => value id, "type" => data type].
     */
    public function exportTypesForResources(array $resourceIds): array
    {
        $resourceIds = array_values(array_unique(array_filter(array_map('intval', $resourceIds))));
        if (!$resourceIds) {
            return [];
        }
        return $this->connection
            ->executeQuery(
                'SELECT `id`, `type` FROM `value` WHERE `value_resource_id` IN (:ids) ORDER BY `id`',
                ['ids' => $resourceIds],
                ['ids' => Connection::PARAM_INT_ARRAY]
            )
            ->fetchAllAssociative();
    }

    /**
     * Restore the data types of some values.
     *
     * @param array $values List of ["id" => value id, "type" => data type].
     * @return int The number of updated values.
     */
    public function restoreTypes(array $values): int
    {
        $byType = [];
        foreach ($values as $value) {
            $id = (int) ($value['id'] ?? 0);
            $type = (string) ($value['type'] ?? '');
            if ($id && $type !== '') {
                $byType[$type][] = $id;
            }
        }

        $total = 0;
        foreach ($byType as $type => $ids) {
            $total += (int) $this->connection->executeStatement(
                'UPDATE `value` SET `type` = :type WHERE `id` IN (:ids)',
                ['type' => $type, 'ids' => $ids],
                ['ids' => Connection::PARAM_INT_ARRAY]
            );
        }
        return $total;
    }

    /**
     * Append the filter on the thesaurus to a statement.
     *
     * @return array Arguments for executeQuery() or executeStatement().
     */
    protected function scope(string $sql, ?array $schemeIds): array
    {
        $schemeIds = array_values(array_unique(array_filter(array_map('intval', $schemeIds ?? []))));
        return $schemeIds
            ? [$sql . "\n    AND `concept`.`scheme_id` IN (:scheme_ids)", ['scheme_ids' => $schemeIds], ['scheme_ids' => Connection::PARAM_INT_ARRAY]]
            : [$sql, [], []];
    }
}
