<?php declare(strict_types=1);

namespace Thesaurus\Job;

use Omeka\Job\AbstractJob;
use Thesaurus\Stdlib\ConceptValues;
use Thesaurus\Stdlib\ItemReferences;
use Thesaurus\Stdlib\ReferencesBackup;
use Thesaurus\Stdlib\TemplateDataTypes;
use Thesaurus\Stdlib\TemplatesSnapshot;

/**
 * Fix the values and the references of the concepts of some thesaurus.
 *
 * The conversion of the items into concepts, done before version 3.4.27, kept
 * the values filled via a custom vocab and the rows that reference the concepts
 * as items. The thesaurus to process are specified by the administrator,
 * because the fix updates and removes data.
 */
class FixConceptReferences extends AbstractJob
{
    public function perform(): void
    {
        $services = $this->getServiceLocator();

        /**
         * @var \Doctrine\DBAL\Connection $connection
         * @var \Laminas\Log\Logger $logger
         */
        $connection = $services->get('Omeka\Connection');

        $referenceIdProcessor = new \Laminas\Log\Processor\ReferenceId();
        $referenceIdProcessor->setReferenceId('thesaurus/fix/job_' . $this->job->getId());

        $logger = $services->get('Omeka\Logger');
        $logger->addProcessor($referenceIdProcessor);

        $schemeIds = $this->schemeIds();
        if (!$schemeIds) {
            $logger->err(
                'No thesaurus to process: select the thesaurus to fix.' // @translate
            );
            return;
        }

        $conceptValues = new ConceptValues($connection);
        $itemReferences = new ItemReferences($connection);

        // The data of the concepts are saved before any change, so the fix can
        // be reverted with the task that converts the concepts into items.
        $conceptIds = array_map('intval', $connection
            ->executeQuery(
                'SELECT `id` FROM `concept` WHERE `scheme_id` IN (:ids)',
                ['ids' => $schemeIds],
                ['ids' => \Doctrine\DBAL\Connection::PARAM_INT_ARRAY]
            )
            ->fetchFirstColumn());
        $references = $itemReferences->exportForConcepts($schemeIds);
        $valueTypes = $conceptValues->exportTypesForResources($conceptIds);
        $templates = (new TemplatesSnapshot($connection))->export();
        if ($references || $valueTypes) {
            try {
                $backupId = (new ReferencesBackup($connection))->save($schemeIds, $references, $valueTypes, $templates);
            } catch (\Exception $e) {
                $backupId = null;
                $logger->err($e->getMessage());
            }
            if (!$backupId) {
                $logger->err(
                    'Unable to save the rows referencing the concepts as items and the data types of the values, so the data are not fixed.' // @translate
                );
                return;
            }
            $logger->notice(
                'The rows referencing the concepts as items and the data types of the values were saved in the backup #{backup_id}.', // @translate
                ['backup_id' => $backupId]
            );
        }

        $totalValues = $conceptValues->retypeCustomVocabValues($schemeIds);
        if ($totalValues) {
            $logger->notice(
                '{count} values pointing to a concept via a custom vocab now use the data type of the thesaurus.', // @translate
                ['count' => $totalValues]
            );
        }

        $totalTemplates = (new TemplateDataTypes($connection))->allowUsedThesaurusTypes($schemeIds);
        if ($totalTemplates) {
            $logger->notice(
                'The data type of the thesaurus was added to {count} template properties used by values of concepts.', // @translate
                ['count' => $totalTemplates]
            );
        }

        $totalReferences = $itemReferences->removeForConcepts($schemeIds);
        if ($totalReferences) {
            $logger->notice(
                '{count} rows referencing a concept as an item were removed or reset.', // @translate
                ['count' => $totalReferences]
            );
        }

        $logger->notice(
            'The {count} thesaurus were processed (#{scheme_ids}).', // @translate
            ['count' => count($schemeIds), 'scheme_ids' => implode(', #', $schemeIds)]
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
        $scheme = (int) $this->getArg('scheme');
        if ($scheme) {
            $schemeIds[] = $scheme;
        }

        $schemeIds = array_values(array_unique(array_filter(array_map('intval', $schemeIds))));
        if (!$schemeIds) {
            return [];
        }

        // Keep only the thesaurus that have concepts.
        return array_map('intval', $this->getServiceLocator()->get('Omeka\Connection')
            ->executeQuery(
                'SELECT DISTINCT `scheme_id` FROM `concept` WHERE `scheme_id` IN (:ids) ORDER BY `scheme_id`',
                ['ids' => $schemeIds],
                ['ids' => \Doctrine\DBAL\Connection::PARAM_INT_ARRAY]
            )
            ->fetchFirstColumn());
    }
}
