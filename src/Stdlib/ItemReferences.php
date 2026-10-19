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
        [$concepts, $params, $types] = $this->conceptIds($schemeIds);

        $total = 0;
        foreach ($this->foreignKeys() as [$table, $column, $deleteRule]) {
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
        [$concepts, $params, $types] = $this->conceptIds($schemeIds);

        $total = 0;
        foreach ($this->foreignKeys() as [$table, $column]) {
            $table = $this->connection->quoteIdentifier($table);
            $column = $this->connection->quoteIdentifier($column);
            $total += (int) $this->connection
                ->executeQuery("SELECT COUNT(*) FROM $table WHERE $column IN ($concepts)", $params, $types)
                ->fetchOne();
        }
        return $total;
    }

    /**
     * Export the rows that reference the concepts as items.
     *
     * The rows are exported before their removal, so the conversion can be
     * reverted: the assignments to item sets and sites and the data of the
     * other modules are restored with the items.
     *
     * @param int[]|null $schemeIds Limit to the concepts of these thesaurus.
     * @return array Rows and metadata by table.
     */
    public function exportForConcepts(?array $schemeIds = null): array
    {
        [$concepts, $params, $types] = $this->conceptIds($schemeIds);

        $result = [];
        foreach ($this->foreignKeys() as [$table, $column, $deleteRule]) {
            $quotedTable = $this->connection->quoteIdentifier($table);
            $quotedColumn = $this->connection->quoteIdentifier($column);
            $rows = $this->connection
                ->executeQuery("SELECT * FROM $quotedTable WHERE $quotedColumn IN ($concepts)", $params, $types)
                ->fetchAllAssociative();
            if (!$rows) {
                continue;
            }
            $result[$table] = [
                'column' => $column,
                'delete_rule' => $deleteRule,
                'primary_key' => $this->primaryKey($table),
                'rows' => $rows,
            ];
        }
        return $result;
    }

    /**
     * Restore the rows exported before a conversion into concepts.
     *
     * A row removed from a table is inserted again and a column set to null is
     * filled again, when the table has a single primary key. The rows that
     * reference a resource that does not exist any more are skipped.
     *
     * @param array $data Result of exportForConcepts().
     * @return int The number of restored rows.
     */
    public function restoreForConcepts(array $data): int
    {
        $total = 0;
        foreach ($data as $table => $tableData) {
            $rows = $tableData['rows'] ?? [];
            if (!$rows || !$this->tableExists((string) $table)) {
                continue;
            }
            $column = (string) ($tableData['column'] ?? '');
            $primaryKey = $tableData['primary_key'] ?? null;
            $isSetNull = ($tableData['delete_rule'] ?? '') === 'SET NULL';
            $quotedTable = $this->connection->quoteIdentifier((string) $table);

            foreach ($rows as $row) {
                if (!is_array($row) || !$row) {
                    continue;
                }
                try {
                    if ($isSetNull) {
                        if (!$primaryKey || !isset($row[$primaryKey]) || !$column) {
                            continue;
                        }
                        $total += $this->connection->update(
                            (string) $table,
                            [$column => $row[$column]],
                            [$primaryKey => $row[$primaryKey]]
                        );
                        continue;
                    }
                    $columns = array_map(fn ($name) => $this->connection->quoteIdentifier((string) $name), array_keys($row));
                    $placeholders = implode(', ', array_fill(0, count($row), '?'));
                    $total += $this->connection->executeStatement(
                        "INSERT IGNORE INTO $quotedTable (" . implode(', ', $columns) . ") VALUES ($placeholders)",
                        array_values($row)
                    );
                } catch (\Exception $e) {
                    // The row cannot be restored, for example when the resource
                    // or the table changed since the conversion.
                    continue;
                }
            }
        }
        return $total;
    }

    /**
     * Get the foreign keys that reference the table "item".
     *
     * @return array List of [table, column, delete rule].
     */
    protected function foreignKeys(): array
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
        return $this->connection->executeQuery($sql)->fetchAllNumeric();
    }

    /**
     * Get the single primary key of a table, if any.
     */
    protected function primaryKey(string $table): ?string
    {
        $sql = <<<'SQL'
            SELECT `COLUMN_NAME`
            FROM `information_schema`.`KEY_COLUMN_USAGE`
            WHERE `TABLE_SCHEMA` = DATABASE()
                AND `TABLE_NAME` = :table
                AND `CONSTRAINT_NAME` = 'PRIMARY'
            SQL;
        $columns = $this->connection
            ->executeQuery($sql, ['table' => $table])
            ->fetchFirstColumn();
        return count($columns) === 1 ? (string) reset($columns) : null;
    }

    protected function tableExists(string $table): bool
    {
        $sql = <<<'SQL'
            SELECT COUNT(*)
            FROM `information_schema`.`TABLES`
            WHERE `TABLE_SCHEMA` = DATABASE()
                AND `TABLE_NAME` = :table
            SQL;
        return (bool) (int) $this->connection
            ->executeQuery($sql, ['table' => $table])
            ->fetchOne();
    }

    /**
     * Get the thesaurus whose concepts are still referenced as items.
     *
     * @param int[]|null $schemeIds Limit to the concepts of these thesaurus.
     * @return int[]
     */
    public function schemeIdsWithReferences(?array $schemeIds = null): array
    {
        [$concepts, $params, $types] = $this->conceptIds($schemeIds);

        $result = [];
        foreach ($this->foreignKeys() as [$table, $column]) {
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
