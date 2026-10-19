<?php declare(strict_types=1);

namespace Thesaurus\Stdlib;

use Doctrine\DBAL\Connection;

/**
 * Store and read the data removed or modified by a conversion into concepts.
 *
 * A concept is not an item, so the assignments to item sets and sites and the
 * data of the other modules about these items are removed, and the data types
 * of the values and of the templates are modified. They are saved in the table
 * "thesaurus_backup", so the conversion can be reverted with the job
 * ConvertConceptsToItems.
 *
 * The table is used instead of the settings, that are fully loaded on each
 * request, and instead of a file, that depends on the rights of the server.
 */
class ReferencesBackup
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
     * Save the data removed or modified by a conversion into concepts.
     *
     * @param int[] $schemeIds
     * @param array $tables Result of ItemReferences::exportForConcepts().
     * @param array $values Result of ConceptValues::exportTypesForResources().
     * @param array $templates Result of TemplatesSnapshot::export().
     * @return int|null The id of the backup, or null when not saved.
     */
    public function save(array $schemeIds, array $tables, array $values = [], array $templates = []): ?int
    {
        if (!$tables && !$values && !$templates) {
            return null;
        }
        $json = json_encode([
            'tables' => $tables,
            'values' => $values,
            'templates' => $templates,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return null;
        }
        $this->connection->insert('thesaurus_backup', [
            'created' => (new \DateTime())->format('Y-m-d H:i:s'),
            'schemes' => json_encode(array_values(array_map('intval', $schemeIds))),
            'data' => $json,
        ]);
        return (int) $this->connection->lastInsertId();
    }

    /**
     * Get the last backup of some thesaurus.
     *
     * @param int[] $schemeIds
     * @return array|null The backup, with the keys "id", "created", "schemes",
     * "tables", "values" and "templates".
     */
    public function readLast(array $schemeIds): ?array
    {
        $schemeIds = array_values(array_map('intval', $schemeIds));
        $rows = $this->connection
            ->executeQuery('SELECT `id`, `schemes` FROM `thesaurus_backup` ORDER BY `id` DESC')
            ->fetchAllKeyValue();
        foreach ($rows as $id => $schemes) {
            $schemes = json_decode((string) $schemes, true) ?: [];
            if (!array_diff($schemeIds, $schemes)) {
                return $this->read((int) $id);
            }
        }
        return null;
    }

    /**
     * Get the last backup of each thesaurus.
     *
     * @return array Id and date of the last backup by thesaurus id.
     */
    public function lastBackups(): array
    {
        $result = [];
        $rows = $this->connection
            ->executeQuery('SELECT `id`, `created`, `schemes` FROM `thesaurus_backup` ORDER BY `id` DESC')
            ->fetchAllAssociative();
        foreach ($rows as $row) {
            foreach (json_decode((string) $row['schemes'], true) ?: [] as $schemeId) {
                $result[(int) $schemeId] ??= ['id' => (int) $row['id'], 'created' => $row['created']];
            }
        }
        return $result;
    }

    /**
     * Read a backup.
     *
     * @return array|null The backup, with the keys "id", "created", "schemes",
     * "tables", "values" and "templates".
     */
    public function read(int $id): ?array
    {
        $row = $this->connection
            ->executeQuery('SELECT * FROM `thesaurus_backup` WHERE `id` = ?', [$id])
            ->fetchAssociative();
        if (!$row) {
            return null;
        }
        $data = json_decode((string) $row['data'], true);
        if (!is_array($data)) {
            return null;
        }
        return [
            'id' => (int) $row['id'],
            'created' => $row['created'],
            'schemes' => json_decode((string) $row['schemes'], true) ?: [],
            'tables' => $data['tables'] ?? [],
            'values' => $data['values'] ?? [],
            'templates' => $data['templates'] ?? [],
        ];
    }

    /**
     * Get the ids of the backups, the most recent first.
     *
     * @return int[]
     */
    public function ids(): array
    {
        return array_map('intval', $this->connection
            ->executeQuery('SELECT `id` FROM `thesaurus_backup` ORDER BY `id` DESC')
            ->fetchFirstColumn());
    }

    public function delete(int $id): void
    {
        $this->connection->delete('thesaurus_backup', ['id' => $id]);
    }
}
