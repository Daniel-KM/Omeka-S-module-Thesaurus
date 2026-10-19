<?php declare(strict_types=1);

namespace Thesaurus\Job;

use Doctrine\DBAL\Connection;
use Omeka\Job\AbstractJob;

/**
 * Convert the concepts of a thesaurus back into items.
 *
 * This is the reverse of the job ConvertItemsToConcepts, in particular to
 * rollback a conversion. The ids and the values are kept, so the links to the
 * concepts remain valid, but the values that point to them use the data type
 * "resource:item" again and the thesaurus is no longer a dedicated resource
 * type: the structure remains available via the skos values, so the thesaurus
 * can be converted again.
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
        foreach ($schemeIds as $schemeId) {
            if ($this->shouldStop()) {
                $this->logger->warn(
                    'The job was stopped: {total} concepts were converted into items.', // @translate
                    ['total' => $totalConverted]
                );
                return;
            }
            $totalConverted += $this->convertScheme($schemeId);
        }

        if (!$totalConverted) {
            $this->logger->notice(
                'No concept to convert into item.' // @translate
            );
            return;
        }

        $this->logger->notice(
            '{total} concepts were converted into items.', // @translate
            ['total' => $totalConverted]
        );
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
