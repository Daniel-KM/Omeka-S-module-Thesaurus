<?php declare(strict_types=1);

namespace Thesaurus\Stdlib;

/**
 * Store and read the rows removed when items are converted into concepts.
 *
 * A concept is not an item, so the assignments to item sets and sites and the
 * data of the other modules about these items are removed. They are saved in a
 * json file of the directory "files/thesaurus", so the conversion can be
 * reverted with the job ConvertConceptsToItems.
 */
class ReferencesBackup
{
    /**
     * @var string
     */
    protected $basePath;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/') . '/thesaurus';
    }

    /**
     * Save the data removed or modified by a conversion into concepts.
     *
     * @param int[] $schemeIds
     * @param array $tables Result of ItemReferences::exportForConcepts().
     * @param array $values Result of ConceptValues::exportTypesForResources().
     * @return string|null The path of the file, or null when not saved.
     */
    public function save(array $schemeIds, array $tables, array $values = []): ?string
    {
        if (!$tables && !$values) {
            return null;
        }
        if (!$this->prepareDirectory()) {
            return null;
        }

        $content = [
            'created' => (new \DateTime())->format('c'),
            'schemes' => array_values(array_map('intval', $schemeIds)),
            'tables' => $tables,
            'values' => $values,
        ];
        $filepath = sprintf(
            '%s/references-%s-%s.json',
            $this->basePath,
            implode('-', $content['schemes']),
            (new \DateTime())->format('Ymd-His')
        );
        $json = json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json !== false && file_put_contents($filepath, $json) !== false
            ? $filepath
            : null;
    }

    /**
     * Get the last saved references of some thesaurus.
     *
     * @param int[] $schemeIds
     * @return array|null The content of the file, with the key "filepath".
     */
    public function readLast(array $schemeIds): ?array
    {
        $schemeIds = array_values(array_map('intval', $schemeIds));
        foreach ($this->files() as $filepath) {
            $content = $this->read($filepath);
            if ($content && !array_diff($schemeIds, $content['schemes'] ?? [])) {
                return $content;
            }
        }
        return null;
    }

    /**
     * Read a file of references.
     *
     * @return array|null The content of the file, with the key "filepath".
     */
    public function read(string $filepath): ?array
    {
        if (!file_exists($filepath) || !is_readable($filepath)) {
            return null;
        }
        $content = json_decode((string) file_get_contents($filepath), true);
        if (!is_array($content) || (empty($content['tables']) && empty($content['values']))) {
            return null;
        }
        $content['tables'] ??= [];
        $content['values'] ??= [];
        $content['filepath'] = $filepath;
        return $content;
    }

    /**
     * Get the files of references, the most recent first.
     *
     * @return string[]
     */
    public function files(): array
    {
        $files = glob($this->basePath . '/references-*.json') ?: [];
        rsort($files);
        return $files;
    }

    protected function prepareDirectory(): bool
    {
        return (is_dir($this->basePath) || @mkdir($this->basePath, 0775, true))
            && is_writeable($this->basePath);
    }
}
