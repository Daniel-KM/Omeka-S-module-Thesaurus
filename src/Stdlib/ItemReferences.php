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
     * @return int The number of removed or updated rows.
     */
    public function removeForConcepts(): int
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
        $total = 0;
        foreach ($this->connection->executeQuery($sql)->fetchAllNumeric() as [$table, $column, $deleteRule]) {
            $table = $this->connection->quoteIdentifier($table);
            $column = $this->connection->quoteIdentifier($column);
            // Follow the rule of the foreign key, as if the item was deleted.
            $total += $deleteRule === 'SET NULL'
                ? $this->connection->executeStatement("UPDATE $table SET $column = NULL WHERE $column IN (SELECT `id` FROM `concept`)")
                : $this->connection->executeStatement("DELETE FROM $table WHERE $column IN (SELECT `id` FROM `concept`)");
        }
        return $total;
    }
}
