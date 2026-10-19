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
