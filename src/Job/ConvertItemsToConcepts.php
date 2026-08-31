<?php declare(strict_types=1);

namespace Thesaurus\Job;

use Doctrine\DBAL\Connection;
use Omeka\Job\AbstractJob;

/**
 * Convert the items of a thesaurus into the dedicated resource type "concept".
 *
 * Until version 3.4.25, the concepts were stored as items and indexed in the
 * table "thesaurus_term". The upgrade converts the existing thesaurus, but the
 * items created later as concepts (bulk import, manual creation, third party
 * import) must be converted too, so this job does the same process, but the
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
                'No thesaurus to convert.' // @translate
            );
            return;
        }

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
     * Get the schemes to process: the argument one or all the existing ones.
     */
    protected function schemeIds(): array
    {
        $schemeId = (int) $this->getArg('scheme');
        if ($schemeId) {
            $sql = <<<'SQL'
                SELECT `id` FROM `resource`
                WHERE `id` = :scheme_id
                    AND `resource_type` = 'Omeka\\Entity\\Item'
                SQL;
            $result = $this->connection
                ->executeQuery($sql, ['scheme_id' => $schemeId])
                ->fetchFirstColumn();
            if (!$result) {
                $this->logger->err(
                    'The thesaurus #{item_id} does not exist or is not an item.', // @translate
                    ['item_id' => $schemeId]
                );
            }
            return array_map('intval', $result);
        }

        // All the resources used as a scheme by a value "skos:inScheme",
        // "skos:topConceptOf" or "skos:hasTopConcept".
        $sql = <<<'SQL'
            SELECT DISTINCT `scheme_id` FROM (
                SELECT `value_resource_id` AS `scheme_id` FROM `value`
                WHERE `property_id` IN (:in_scheme, :top_concept_of)
                    AND `value_resource_id` IS NOT NULL
                UNION
                SELECT `resource_id` AS `scheme_id` FROM `value`
                WHERE `property_id` = :has_top_concept
                    AND `value_resource_id` IS NOT NULL
            ) AS `schemes`
            SQL;
        $result = $this->connection
            ->executeQuery($sql, [
                'in_scheme' => $this->properties['skos:inScheme'],
                'top_concept_of' => $this->properties['skos:topConceptOf'],
                'has_top_concept' => $this->properties['skos:hasTopConcept'],
            ], [
                'in_scheme' => \PDO::PARAM_INT,
                'top_concept_of' => \PDO::PARAM_INT,
                'has_top_concept' => \PDO::PARAM_INT,
            ])
            ->fetchFirstColumn();

        return array_map('intval', $result);
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

        // The item rows are removed without cascade, so the assignment to item
        // sets and sites is kept: the custom vocab of a thesaurus is based on
        // an item set and it should continue to work until its migration to the
        // data type "thesaurus".
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
