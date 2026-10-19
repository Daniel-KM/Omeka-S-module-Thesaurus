<?php declare(strict_types=1);

namespace Thesaurus\Job;

use Doctrine\DBAL\Connection;
use Omeka\Job\AbstractJob;
use Thesaurus\Stdlib\ConceptTemplates;
use Thesaurus\Stdlib\ConceptValues;
use Thesaurus\Stdlib\ItemReferences;
use Thesaurus\Stdlib\ReferencesBackup;

/**
 * Convert the concepts of a thesaurus back into items.
 *
 * This is the reverse of the job ConvertItemsToConcepts, in particular to
 * rollback a conversion. The ids and the values are kept, so the links to the
 * concepts remain valid, but the values that point to them use the data type
 * "resource:item" again and the thesaurus is no longer a dedicated resource
 * type: the structure remains available via the skos values, so the thesaurus
 * can be converted again.
 *
 * The assignments to item sets and sites, the data of the other modules and the
 * data types of the values, removed or modified by the conversion, are restored
 * from the file saved by it, unless the argument "restore" is false. Another
 * file can be used with this argument. Without this file, the values get the
 * data type "resource:item": the custom vocab they used before the conversion
 * is unknown.
 */
class ConvertConceptsToItems extends AbstractJob
{
    /**
     * @var \Laminas\Log\Logger
     */
    protected $logger;

    /**
     * @var \Doctrine\DBAL\Connection
     */
    protected $connection;

    public function perform(): void
    {
        $services = $this->getServiceLocator();

        $referenceIdProcessor = new \Laminas\Log\Processor\ReferenceId();
        $referenceIdProcessor->setReferenceId('thesaurus/revert/job_' . $this->job->getId());

        $this->logger = $services->get('Omeka\Logger');
        $this->logger->addProcessor($referenceIdProcessor);

        $this->connection = $services->get('Omeka\Connection');

        $schemeIds = $this->schemeIds();
        if (!$schemeIds) {
            $this->logger->warn(
                'No thesaurus to convert: select the thesaurus to process.' // @translate
            );
            return;
        }

        $totalConverted = 0;
        $convertedSchemeIds = [];
        foreach ($schemeIds as $schemeId) {
            if ($this->shouldStop()) {
                $this->logger->warn(
                    'The job was stopped: {total} concepts were converted into items.', // @translate
                    ['total' => $totalConverted]
                );
                return;
            }
            $converted = $this->convertScheme($schemeId);
            if ($converted) {
                $totalConverted += $converted;
                $convertedSchemeIds[] = $schemeId;
            }
        }

        if (!$totalConverted) {
            $this->logger->notice(
                'No concept to convert into item.' // @translate
            );
            return;
        }

        $this->restoreReferences($convertedSchemeIds);

        // The templates of the thesaurus may be reserved to the concepts, so
        // the values of the new items could not be saved any more.
        $services = $this->getServiceLocator();
        $totalTemplates = (new ConceptTemplates(
            $this->connection,
            $services->get('Common\EasyMeta'),
            $services->get('Omeka\Settings'),
            $services->get('Omeka\EntityManager')
        ))->allowItems();
        if ($totalTemplates) {
            $this->logger->notice(
                'The data type of the items was added to {count} template properties.', // @translate
                ['count' => $totalTemplates]
            );
        }

        $this->logger->notice(
            '{total} concepts were converted into items.', // @translate
            ['total' => $totalConverted]
        );

        $this->logger->warn(
            'The rollback may be incomplete: the rows whose target was removed since the conversion, the rows of the tables without a single primary key and the data that were not saved are not restored. Check the thesaurus and the related resources.' // @translate
        );
    }

    /**
     * Restore the rows removed by the conversion of the items into concepts.
     *
     * @param int[] $schemeIds
     */
    protected function restoreReferences(array $schemeIds): void
    {
        $restore = $this->getArg('restore', true);
        if ($restore === false || $restore === '0' || $restore === 'no') {
            $this->logger->notice(
                'The rows and the data types saved by the conversion were not restored.' // @translate
            );
            return;
        }

        $backup = new ReferencesBackup($this->basePath());
        $content = is_string($restore) && $restore !== '' && $restore !== '1' && $restore !== 'yes'
            ? $backup->read($restore)
            : $backup->readLast($schemeIds);
        if (!$content) {
            $this->logger->warn(
                'No saved data were found for these thesaurus: the assignments to item sets and sites are not restored and the values keep the data type "resource:item".' // @translate
            );
            return;
        }

        $total = (new ItemReferences($this->connection))->restoreForConcepts($content['tables'] ?? []);
        $totalValues = (new ConceptValues($this->connection))->restoreTypes($content['values'] ?? []);
        $this->logger->notice(
            '{count} rows referencing the items and the data types of {count_values} values were restored from {filename}.', // @translate
            [
                'count' => $total,
                'count_values' => $totalValues,
                'filename' => basename((string) $content['filepath']),
            ]
        );
    }

    /**
     * Get the base path of the files.
     */
    protected function basePath(): string
    {
        $config = $this->getServiceLocator()->get('Config');
        return $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
    }

    /**
     * Get the thesaurus to process, specified by the administrator.
     *
     * @return int[]
     */
    protected function schemeIds(): array
    {
        $schemeIds = $this->getArg('schemes') ?: [];
        if (!is_array($schemeIds)) {
            $schemeIds = [$schemeIds];
        }
        $schemeId = (int) $this->getArg('scheme');
        if ($schemeId) {
            $schemeIds[] = $schemeId;
        }

        $schemeIds = array_values(array_unique(array_filter(array_map('intval', $schemeIds))));
        if (!$schemeIds) {
            return [];
        }

        return array_map('intval', $this->connection
            ->executeQuery(
                'SELECT DISTINCT `scheme_id` FROM `concept` WHERE `scheme_id` IN (:ids) ORDER BY `scheme_id`',
                ['ids' => $schemeIds],
                ['ids' => Connection::PARAM_INT_ARRAY]
            )
            ->fetchFirstColumn());
    }

    /**
     * Convert all the concepts of a scheme into items.
     */
    protected function convertScheme(int $schemeId): int
    {
        $conceptIds = array_map('intval', $this->connection
            ->executeQuery(
                'SELECT `id` FROM `concept` WHERE `scheme_id` = :scheme_id ORDER BY `id`',
                ['scheme_id' => $schemeId],
                ['scheme_id' => \PDO::PARAM_INT]
            )
            ->fetchFirstColumn());
        if (!$conceptIds) {
            return 0;
        }

        // The concept rows are removed without cascade, so the check is
        // disabled, like for the conversion of the items into concepts.
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');

        $sqls = [
            <<<'SQL'
                INSERT INTO `item` (`id`)
                SELECT `id` FROM `resource` WHERE `id` IN (:ids)
                SQL,
            <<<'SQL'
                UPDATE `resource` SET `resource_type` = 'Omeka\\Entity\\Item'
                WHERE `id` IN (:ids)
                SQL,
            // A value pointing to an item uses the data type "resource:item",
            // so the types specific to the concepts are replaced, including the
            // data type of the thesaurus itself.
            <<<'SQL'
                UPDATE `value` SET `type` = 'resource:item'
                WHERE `value_resource_id` IN (:ids)
                    AND (`type` = 'resource:concept' OR `type` LIKE 'thesaurus:%')
                SQL,
            <<<'SQL'
                DELETE FROM `concept` WHERE `id` IN (:ids)
                SQL,
        ];
        try {
            foreach ($sqls as $sql) {
                $this->connection->executeStatement(
                    $sql,
                    ['ids' => $conceptIds],
                    ['ids' => Connection::PARAM_INT_ARRAY]
                );
            }
        } finally {
            // The check is restored in all cases, else the connection would
            // keep it disabled after a failure.
            $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }

        $this->logger->notice(
            'Thesaurus #{item_id}: {count} concepts were converted into items.', // @translate
            ['item_id' => $schemeId, 'count' => count($conceptIds)]
        );

        return count($conceptIds);
    }
}
