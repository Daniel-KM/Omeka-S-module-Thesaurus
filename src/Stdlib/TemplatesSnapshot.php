<?php declare(strict_types=1);

namespace Thesaurus\Stdlib;

use Doctrine\DBAL\Connection;

/**
 * Save and restore the configurations of the templates about the thesaurus.
 *
 * The conversion of the items into concepts adds the data type of the converted
 * thesaurus to the templates, so the previous configurations are saved to be
 * able to revert it. Only the rows that mention a custom vocab or a thesaurus
 * are saved, so the snapshot remains small.
 *
 * The restoration is differential: only the data types of the reverted
 * thesaurus that were missing before the conversion are removed, so the changes
 * done later, for example by the conversion of another thesaurus, are kept.
 */
class TemplatesSnapshot
{
    /**
     * Columns to save, by table.
     *
     * @var array
     */
    const COLUMNS = [
        'resource_template_property' => 'data_type',
        'resource_template_property_data' => 'data',
    ];

    /**
     * @var \Doctrine\DBAL\Connection
     */
    protected $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Export the configurations of the templates about the thesaurus.
     *
     * @return array Rows by table.
     */
    public function export(): array
    {
        $result = [];
        foreach (self::COLUMNS as $table => $column) {
            if (!$this->tableExists($table)) {
                continue;
            }
            $quotedTable = $this->connection->quoteIdentifier($table);
            $quotedColumn = $this->connection->quoteIdentifier($column);
            $rows = $this->connection
                ->executeQuery(
                    "SELECT `id`, $quotedColumn AS `value` FROM $quotedTable"
                        . " WHERE $quotedColumn LIKE '%customvocab:%'"
                        . " OR $quotedColumn LIKE '%thesaurus:%'"
                )
                ->fetchAllAssociative();
            if ($rows) {
                $result[$table] = ['column' => $column, 'rows' => $rows];
            }
        }
        return $result;
    }

    /**
     * Remove the data types of some thesaurus added since the snapshot.
     *
     * @param array $data Result of export().
     * @param int[] $schemeIds The reverted thesaurus.
     * @return int The number of updated rows.
     */
    public function restore(array $data, array $schemeIds): int
    {
        $types = array_map(fn ($id) => 'thesaurus:' . (int) $id, array_filter(array_map('intval', $schemeIds)));
        if (!$types) {
            return 0;
        }

        $total = 0;
        foreach (array_keys(self::COLUMNS) as $table) {
            $rows = $data[$table]['rows'] ?? [];
            if (!$rows || !$this->tableExists($table)) {
                continue;
            }
            $column = self::COLUMNS[$table];
            $quotedTable = $this->connection->quoteIdentifier($table);
            $quotedColumn = $this->connection->quoteIdentifier($column);
            foreach ($rows as $row) {
                if (!isset($row['id'])) {
                    continue;
                }
                $current = $this->connection
                    ->executeQuery("SELECT $quotedColumn FROM $quotedTable WHERE `id` = ?", [$row['id']])
                    ->fetchOne();
                if ($current === false || $current === null) {
                    continue;
                }
                $new = $table === 'resource_template_property'
                    ? $this->removeTypes((string) $current, (string) ($row['value'] ?? ''), $types)
                    : $this->removeTypesInData((string) $current, (string) ($row['value'] ?? ''), $types);
                if ($new !== null) {
                    $total += $this->connection->update($table, [$column => $new], ['id' => $row['id']]);
                }
            }
        }
        return $total;
    }

    /**
     * Remove the added types from a json list of data types.
     *
     * @return string|null The new json, or null when unchanged.
     */
    protected function removeTypes(string $current, string $previous, array $types): ?string
    {
        $currentList = json_decode($current, true);
        if (!is_array($currentList)) {
            return null;
        }
        $previousList = json_decode($previous, true) ?: [];
        $result = $this->filterList($currentList, (array) $previousList, $types);
        return $result === null ? null : json_encode($result);
    }

    /**
     * Remove the added types from the data types of the data of a template
     * property (module Advanced Resource Template).
     *
     * @return string|null The new json, or null when unchanged.
     */
    protected function removeTypesInData(string $current, string $previous, array $types): ?string
    {
        $currentData = json_decode($current, true);
        if (!is_array($currentData) || !isset($currentData['o:data_type']) || !is_array($currentData['o:data_type'])) {
            return null;
        }
        $previousData = json_decode($previous, true) ?: [];
        $result = $this->filterList($currentData['o:data_type'], (array) ($previousData['o:data_type'] ?? []), $types);
        if ($result === null) {
            return null;
        }
        $currentData['o:data_type'] = $result;
        return json_encode($currentData);
    }

    /**
     * Remove the types that were added since the previous list.
     *
     * @return array|null The new list, or null when unchanged.
     */
    protected function filterList(array $current, array $previous, array $types): ?array
    {
        $result = [];
        $changed = false;
        foreach ($current as $type) {
            if (in_array($type, $types, true) && !in_array($type, $previous, true)) {
                $changed = true;
                continue;
            }
            $result[] = $type;
        }
        return $changed ? $result : null;
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
}
