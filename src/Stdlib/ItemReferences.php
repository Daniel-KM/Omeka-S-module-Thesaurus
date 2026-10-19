<?php declare(strict_types=1);

namespace Thesaurus\Stdlib;

use Doctrine\DBAL\Connection;

/**
 * Remove the rows that reference concepts as items.
 *
 * The rows of the table "item" of the converted concepts are removed without
 * cascade, so the rows of the core and of the modules that reference them via a
 * foreign key (item sets, sites, mapping features…) remain. Doctrine loads them
 * as items that do not exist, so the pages that use them fail.
 */
class ItemReferences
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
     * @param int[]|null $schemeIds Limit to the concepts of these thesaurus.
     * @return int The number of removed or updated rows.
     */
    public function removeForConcepts(?array $schemeIds = null): int
    {
        $sql = <<<'SQL'
            SELECT `k`.`TABLE_NAME`, `k`.`COLUMN_NAME`, `r`.`DELETE_RULE`
            FROM `information_schema`.`KEY_COLUMN_USAGE` AS `k`
            INNER JOIN `information_schema`.`REFERENTIAL_CONSTRAINTS` AS `r`
                ON `r`.`CONSTRAINT_SCHEMA` = `k`.`CONSTRAINT_SCHEMA`
                AND `r`.`CONSTRAINT_NAME` = `k`.`CONSTRAINT_NAME`
            WHERE `k`.`TABLE_SCHEMA` = DATABASE()
                AND `k`.`REFERENCED_TABLE_NAME` = 'item'
                AND `k`.`REFERENCED_COLUMN_NAME` = 'id'
                AND `k`.`TABLE_NAME` != 'concept'
            SQL;
        [$concepts, $params, $types] = $this->conceptIds($schemeIds);

        $total = 0;
        foreach ($this->connection->executeQuery($sql)->fetchAllNumeric() as [$table, $column, $deleteRule]) {
            $table = $this->connection->quoteIdentifier($table);
            $column = $this->connection->quoteIdentifier($column);
            // Follow the rule of the foreign key, as if the item was deleted.
            $statement = $deleteRule === 'SET NULL'
                ? "UPDATE $table SET $column = NULL WHERE $column IN ($concepts)"
                : "DELETE FROM $table WHERE $column IN ($concepts)";
            $total += $this->connection->executeStatement($statement, $params, $types);
        }
        return $total;
    }

    /**
     * @param int[]|null $schemeIds Limit to the concepts of these thesaurus.
     * @return int The number of rows to remove or to update.
     */
    public function countForConcepts(?array $schemeIds = null): int
    {
        $sql = <<<'SQL'
            SELECT `k`.`TABLE_NAME`, `k`.`COLUMN_NAME`
            FROM `information_schema`.`KEY_COLUMN_USAGE` AS `k`
            WHERE `k`.`TABLE_SCHEMA` = DATABASE()
                AND `k`.`REFERENCED_TABLE_NAME` = 'item'
                AND `k`.`REFERENCED_COLUMN_NAME` = 'id'
                AND `k`.`TABLE_NAME` != 'concept'
            SQL;
        [$concepts, $params, $types] = $this->conceptIds($schemeIds);

        $total = 0;
        foreach ($this->connection->executeQuery($sql)->fetchAllNumeric() as [$table, $column]) {
            $table = $this->connection->quoteIdentifier($table);
            $column = $this->connection->quoteIdentifier($column);
            $total += (int) $this->connection
                ->executeQuery("SELECT COUNT(*) FROM $table WHERE $column IN ($concepts)", $params, $types)
                ->fetchOne();
        }
        return $total;
    }

    /**
     * Get the thesaurus whose concepts are still referenced as items.
     *
     * @param int[]|null $schemeIds Limit to the concepts of these thesaurus.
     * @return int[]
     */
    public function schemeIdsWithReferences(?array $schemeIds = null): array
    {
        $sql = <<<'SQL'
            SELECT `k`.`TABLE_NAME`, `k`.`COLUMN_NAME`
            FROM `information_schema`.`KEY_COLUMN_USAGE` AS `k`
            WHERE `k`.`TABLE_SCHEMA` = DATABASE()
                AND `k`.`REFERENCED_TABLE_NAME` = 'item'
                AND `k`.`REFERENCED_COLUMN_NAME` = 'id'
                AND `k`.`TABLE_NAME` != 'concept'
            SQL;
        [$concepts, $params, $types] = $this->conceptIds($schemeIds);

        $result = [];
        foreach ($this->connection->executeQuery($sql)->fetchAllNumeric() as [$table, $column]) {
            $table = $this->connection->quoteIdentifier($table);
            $column = $this->connection->quoteIdentifier($column);
            $ids = $this->connection
                ->executeQuery(
                    "SELECT DISTINCT `concept`.`scheme_id` FROM $table"
                        . " INNER JOIN `concept` ON `concept`.`id` = $table.$column"
                        . " WHERE $column IN ($concepts)",
                    $params,
                    $types
                )
                ->fetchFirstColumn();
            foreach ($ids as $id) {
                $id = (int) $id;
                if ($id && !in_array($id, $result)) {
                    $result[] = $id;
                }
            }
        }
        return $result;
    }

    /**
     * Get the sub-query of the concepts and its parameters.
     */
    protected function conceptIds(?array $schemeIds): array
    {
        $schemeIds = array_values(array_unique(array_filter(array_map('intval', $schemeIds ?? []))));
        return $schemeIds
            ? ['SELECT `id` FROM `concept` WHERE `scheme_id` IN (:scheme_ids)', ['scheme_ids' => $schemeIds], ['scheme_ids' => Connection::PARAM_INT_ARRAY]]
            : ['SELECT `id` FROM `concept`', [], []];
    }
}
