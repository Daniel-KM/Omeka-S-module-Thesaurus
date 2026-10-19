<?php declare(strict_types=1);

namespace Thesaurus\Job;

use Doctrine\DBAL\Connection;
use Omeka\Job\AbstractJob;
use Thesaurus\Stdlib\ConceptValues;
use Thesaurus\Stdlib\ItemReferences;
use Thesaurus\Stdlib\ReferencesBackup;
use Thesaurus\Stdlib\TemplateDataTypes;
use Thesaurus\Stdlib\TemplatesSnapshot;

/**
 * Convert the items of a thesaurus into the dedicated resource type "concept".
 *
 * Until version 3.4.25, the concepts were stored as items and indexed in the
 * table "thesaurus_term". The conversion is not done automatically during the
 * upgrade, because it updates and removes data: the administrator selects the
 * thesaurus to convert. The items created as concepts by a bulk import, a
 * manual creation or a third party import are converted the same way: the
 * structure is determined from the skos values, not from the old index table.
 *
 * The values pointing to the converted concepts are updated to the data type
 * "resource:concept". The tree (top concept, broader, position) is rebuilt by
 * the job IndexThesaurus at the end.
 */
class ConvertItemsToConcepts extends AbstractJob
{
    /**
     * Maximum loops to get the concepts related to a scheme via broader and
     * narrower, in order to avoid infinite loops.
     *
     * @var int
     */
    const MAX_LOOPS = 100;

    /**
     * @var \Laminas\Log\Logger
     */
    protected $logger;

    /**
     * @var \Doctrine\DBAL\Connection
     */
    protected $connection;

    /**
     * @var array
     */
    protected $properties;

    /**
     * Configurations of the templates before the conversion.
     *
     * @var array
     */
    protected $templatesSnapshot = [];

    public function perform(): void
    {
        $services = $this->getServiceLocator();

        $referenceIdProcessor = new \Laminas\Log\Processor\ReferenceId();
        $referenceIdProcessor->setReferenceId('thesaurus/convert/job_' . $this->job->getId());

        $this->logger = $services->get('Omeka\Logger');
        $this->logger->addProcessor($referenceIdProcessor);

        $this->connection = $services->get('Omeka\Connection');

        $easyMeta = $services->get('Common\EasyMeta');
        $this->properties = [];
        foreach ([
            'skos:inScheme',
            'skos:topConceptOf',
            'skos:hasTopConcept',
            'skos:broader',
            'skos:narrower',
        ] as $term) {
            $id = $easyMeta->propertyId($term);
            if (!$id) {
                $this->logger->err(
                    'The property {term} is not available: the vocabulary skos is required.', // @translate
                    ['term' => $term]
                );
                return;
            }
            $this->properties[$term] = $id;
        }

        $schemeIds = $this->schemeIds();
        if (!$schemeIds) {
            $this->logger->warn(
                'No thesaurus to convert: select the thesaurus to process.' // @translate
            );
            return;
        }

        // The configurations of the templates are modified below, so they are
        // saved first, with the rows and the data types of each thesaurus.
        $this->templatesSnapshot = (new TemplatesSnapshot($this->connection))->export();

        $totalConverted = 0;
        $convertedSchemeIds = [];
        foreach ($schemeIds as $schemeId) {
            if ($this->shouldStop()) {
                $this->logger->warn(
                    'The job was stopped: {total} concepts were converted.', // @translate
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
                'No item to convert into concept: the thesaurus are up to date.' // @translate
            );
            return;
        }

        $totalTemplates = (new TemplateDataTypes($this->connection))
            ->allowUsedThesaurusTypes($convertedSchemeIds);
        if ($totalTemplates) {
            $this->logger->notice(
                'The data type of the thesaurus was added to {count} template properties used by values of concepts.', // @translate
                ['count' => $totalTemplates]
            );
        }

        // The concepts are no longer items, so the custom vocab of a thesaurus
        // is empty: the templates that use it must accept the data type of the
        // thesaurus, else no concept could be selected any more. Only the
        // converted thesaurus are processed.
        (new MigrateDataTypes($this->job, $services))
            ->setSchemeIds($convertedSchemeIds)
            ->perform();

        // Only the converted thesaurus are indexed: they may have no resource
        // template or class allowing to detect them as a scheme. Do not
        // dispatch to avoid issue with doctrine: authenticated owner should be
        // refreshed.
        $indexing = new IndexThesaurus($this->job, $services);
        $indexing
            ->setSchemeIds($convertedSchemeIds)
            ->perform();

        $this->logger->notice(
            '{total} items were converted into concepts and the thesaurus were reindexed.', // @translate
            ['total' => $totalConverted]
        );
    }

    /**
     * Get the thesaurus to process, specified by the administrator.
     *
     * The conversion updates and removes data, so the thesaurus are never
     * determined automatically.
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

        $sql = <<<'SQL'
            SELECT `id` FROM `resource`
            WHERE `id` IN (:ids)
                AND `resource_type` = 'Omeka\\Entity\\Item'
            ORDER BY `id`
            SQL;
        $result = array_map('intval', $this->connection
            ->executeQuery($sql, ['ids' => $schemeIds], ['ids' => Connection::PARAM_INT_ARRAY])
            ->fetchFirstColumn());

        $missing = array_diff($schemeIds, $result);
        foreach ($missing as $missingId) {
            $this->logger->err(
                'The thesaurus #{item_id} does not exist or is not an item.', // @translate
                ['item_id' => $missingId]
            );
        }

        return $result;
    }

    /**
     * Convert all the items of a scheme that are not yet concepts.
     */
    protected function convertScheme(int $schemeId): int
    {
        $itemIds = $this->conceptItemIds($schemeId);
        if (!$itemIds) {
            return 0;
        }

        // A concept has no media, so an item with files cannot be converted.
        // The conversion of the whole thesaurus is skipped in that case, else
        // it would be partially converted and no more consistent.
        $withMedia = $this->connection
            ->executeQuery(
                'SELECT DISTINCT `item_id` FROM `media` WHERE `item_id` IN (:ids) ORDER BY `item_id`',
                ['ids' => $itemIds],
                ['ids' => Connection::PARAM_INT_ARRAY]
            )
            ->fetchFirstColumn();
        if ($withMedia) {
            $this->logger->err(
                'The conversion of the thesaurus #{item_id} is not possible: {count} items have media (#{item_ids}). Remove the media or exclude these items from the thesaurus first.', // @translate
                ['item_id' => $schemeId, 'count' => count($withMedia), 'item_ids' => implode(', #', $withMedia)]
            );
            return 0;
        }

        // The rows that reference the items and the data types of the values
        // that point to them are saved before any change, so the conversion can
        // be reverted. Without backup, the thesaurus is not converted.
        $itemReferences = new ItemReferences($this->connection);
        $backupId = $this->saveReferences(
            $schemeId,
            $itemReferences->exportForResources($itemIds),
            (new ConceptValues($this->connection))->exportTypesForResources($itemIds)
        );
        if ($backupId === false) {
            return 0;
        }

        // The item rows are removed without cascade, so the rows that reference
        // them are removed separately.
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');

        $sqls = [
            // The scheme is the same for all the concepts of the thesaurus. The
            // tree is set by the job IndexThesaurus.
            <<<'SQL'
                INSERT INTO `concept` (`id`, `scheme_id`)
                SELECT `id`, :scheme_id FROM `resource` WHERE `id` IN (:ids)
                SQL,
            <<<'SQL'
                UPDATE `resource` SET `resource_type` = 'Thesaurus\\Entity\\Concept'
                WHERE `id` IN (:ids)
                SQL,
            <<<'SQL'
                DELETE FROM `item` WHERE `id` IN (:ids)
                SQL,
            // Any value pointing to a concept must use the data type
            // "resource:concept", including the values of the scheme itself.
            <<<'SQL'
                UPDATE `value` SET `type` = 'resource:concept'
                WHERE `value_resource_id` IN (:ids)
                    AND `type` IN ('resource', 'resource:item')
                SQL,
        ];
        try {
            foreach ($sqls as $sql) {
                $this->connection->executeStatement(
                    $sql,
                    ['scheme_id' => $schemeId, 'ids' => $itemIds],
                    ['scheme_id' => \PDO::PARAM_INT, 'ids' => Connection::PARAM_INT_ARRAY]
                );
            }

            // A concept is not an item, so it is no more assigned to item sets
            // or sites, and the data of the modules about items are removed.
            $itemReferences->removeForConcepts([$schemeId]);

            // The old index table, used until version 3.4.25, references the
            // concepts as items, so its rows are removed with them.
            $this->cleanOldIndex($itemIds);

            // A custom vocab contains items, so a value filled with a concept
            // via the custom vocab of the thesaurus is no longer valid: it uses
            // the data type of the thesaurus, that manages the concepts.
            $this->connection->executeStatement(
                <<<'SQL'
                    UPDATE `value` SET `type` = :thesaurus_type
                    WHERE `value_resource_id` IN (:ids)
                        AND `type` LIKE 'customvocab:%'
                    SQL,
                ['thesaurus_type' => 'thesaurus:' . $schemeId, 'ids' => $itemIds],
                ['ids' => Connection::PARAM_INT_ARRAY]
            );
        } finally {
            // The check is restored in all cases, else the connection would
            // keep it disabled after a failure.
            $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }

        $this->logger->notice(
            'Thesaurus #{item_id}: {count} items were converted into concepts.', // @translate
            ['item_id' => $schemeId, 'count' => count($itemIds)]
        );

        return count($itemIds);
    }

    /**
     * Save the rows and the data types modified by the conversion.
     *
     * @param array $references Result of ItemReferences::exportForResources().
     * @param array $valueTypes Result of ConceptValues::exportTypesForResources().
     * @return int|false|null The id of the backup, null when there is nothing
     * to save, false when the backup failed.
     */
    protected function saveReferences(int $schemeId, array $references, array $valueTypes)
    {
        if (!$references && !$valueTypes && !$this->templatesSnapshot) {
            return null;
        }

        $total = 0;
        foreach ($references as $tableData) {
            $total += count($tableData['rows'] ?? []);
        }

        try {
            $backupId = (new ReferencesBackup($this->connection))
                ->save([$schemeId], $references, $valueTypes, $this->templatesSnapshot);
        } catch (\Exception $e) {
            $backupId = null;
            $this->logger->err(
                'Thesaurus #{item_id}: {message}', // @translate
                ['item_id' => $schemeId, 'message' => $e->getMessage()]
            );
        }

        if (!$backupId) {
            $this->logger->err(
                'Thesaurus #{item_id}: unable to save the {count} rows referencing the items and the data types of {count_values} values, so the thesaurus is not converted.', // @translate
                ['item_id' => $schemeId, 'count' => $total, 'count_values' => count($valueTypes)]
            );
            return false;
        }

        $this->logger->notice(
            'Thesaurus #{item_id}: {count} rows referencing the items and the data types of {count_values} values were saved in the backup #{backup_id}, so the conversion can be reverted.', // @translate
            ['item_id' => $schemeId, 'count' => $total, 'count_values' => count($valueTypes), 'backup_id' => $backupId]
        );
        return $backupId;
    }

    /**
     * Remove the converted concepts from the old index table "thesaurus_term".
     *
     * The table is dropped once all the concepts are converted: it is useless
     * for the concepts, that are a dedicated resource type since version
     * 3.4.26.
     *
     * @param int[] $itemIds
     */
    protected function cleanOldIndex(array $itemIds): void
    {
        $sql = <<<'SQL'
            SELECT COUNT(*)
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'thesaurus_term'
            SQL;
        if (!(int) $this->connection->executeQuery($sql)->fetchOne()) {
            return;
        }

        $this->connection->executeStatement(
            'DELETE FROM `thesaurus_term` WHERE `item_id` IN (:ids) OR `scheme_id` IN (:ids)',
            ['ids' => $itemIds],
            ['ids' => Connection::PARAM_INT_ARRAY]
        );

        $remaining = (int) $this->connection
            ->executeQuery('SELECT COUNT(*) FROM `thesaurus_term`')
            ->fetchOne();
        if (!$remaining) {
            $this->connection->executeStatement('DROP TABLE `thesaurus_term`');
            $this->logger->notice(
                'All the concepts are converted: the old index table was removed.' // @translate
            );
        }
    }

    /**
     * Get the items that belong to a scheme and that are not yet concepts.
     *
     * The concepts are the items related to the scheme via "skos:inScheme",
     * "skos:topConceptOf" or "skos:hasTopConcept", and all the items related to
     * them via "skos:broader" and "skos:narrower", recursively.
     */
    protected function conceptItemIds(int $schemeId): array
    {
        $sql = <<<'SQL'
            SELECT DISTINCT `id` FROM (
                SELECT `resource_id` AS `id` FROM `value`
                WHERE `property_id` IN (:in_scheme, :top_concept_of)
                    AND `value_resource_id` = :scheme_id
                UNION
                SELECT `value_resource_id` AS `id` FROM `value`
                WHERE `property_id` = :has_top_concept
                    AND `resource_id` = :scheme_id
            ) AS `concepts`
            SQL;
        $ids = $this->connection
            ->executeQuery($sql, [
                'scheme_id' => $schemeId,
                'in_scheme' => $this->properties['skos:inScheme'],
                'top_concept_of' => $this->properties['skos:topConceptOf'],
                'has_top_concept' => $this->properties['skos:hasTopConcept'],
            ], [
                'scheme_id' => \PDO::PARAM_INT,
                'in_scheme' => \PDO::PARAM_INT,
                'top_concept_of' => \PDO::PARAM_INT,
                'has_top_concept' => \PDO::PARAM_INT,
            ])
            ->fetchFirstColumn();
        $ids = array_map('intval', $ids);
        if (!$ids) {
            return [];
        }

        // Append the concepts that are related to the first ones, but that have
        // no value "skos:inScheme", for example a partial import.
        $sql = <<<'SQL'
            SELECT DISTINCT `id` FROM (
                SELECT `resource_id` AS `id` FROM `value`
                WHERE `property_id` IN (:broader, :narrower)
                    AND `value_resource_id` IN (:ids)
                UNION
                SELECT `value_resource_id` AS `id` FROM `value`
                WHERE `property_id` IN (:broader, :narrower)
                    AND `resource_id` IN (:ids)
            ) AS `concepts`
            SQL;
        $params = [
            'broader' => $this->properties['skos:broader'],
            'narrower' => $this->properties['skos:narrower'],
        ];
        $types = [
            'broader' => \PDO::PARAM_INT,
            'narrower' => \PDO::PARAM_INT,
            'ids' => Connection::PARAM_INT_ARRAY,
        ];
        for ($loop = 0; $loop < self::MAX_LOOPS; ++$loop) {
            $related = $this->connection
                ->executeQuery($sql, $params + ['ids' => $ids], $types)
                ->fetchFirstColumn();
            $new = array_diff(array_map('intval', $related), $ids);
            if (!$new) {
                break;
            }
            $ids = array_merge($ids, array_values($new));
        }

        // Keep only the resources that are still items: the scheme is an item
        // too, but it is never a concept.
        $sql = <<<'SQL'
            SELECT `id` FROM `resource`
            WHERE `id` IN (:ids)
                AND `id` != :scheme_id
                AND `resource_type` = 'Omeka\\Entity\\Item'
            SQL;
        $ids = $this->connection
            ->executeQuery($sql, [
                'ids' => $ids,
                'scheme_id' => $schemeId,
            ], [
                'ids' => Connection::PARAM_INT_ARRAY,
                'scheme_id' => \PDO::PARAM_INT,
            ])
            ->fetchFirstColumn();

        return array_map('intval', $ids);
    }
}
