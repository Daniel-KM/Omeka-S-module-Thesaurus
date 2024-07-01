<?php declare(strict_types=1);

namespace Thesaurus\Job;

use Omeka\Job\AbstractJob;

class UpdateConcepts extends AbstractJob
{
    /**
     * Limit for the loop to avoid heavy sql requests.
     *
     * @var int
     */
    const BATCH_SIZE = 100;

    /**
     * @var \Omeka\Api\Manager
     */
    protected $api;

    /**
     * @var \Doctrine\ORM\EntityManager
     */
    protected $entityManager;

    /**
     * @var \Laminas\Log\Logger
     */
    protected $logger;

    /**
     * @var \Omeka\Settings\Settings
     */
    protected $settings;

    public function perform(): void
    {
        $services = $this->getServiceLocator();

        // The reference id is the job id for now.
        $referenceIdProcessor = new \Laminas\Log\Processor\ReferenceId();
        $referenceIdProcessor->setReferenceId('thesaurus/update/job_' . $this->job->getId());

        $this->logger = $services->get('Omeka\Logger');
        $this->logger->addProcessor($referenceIdProcessor);

        $this->entityManager = $services->get('Omeka\EntityManager');

        $this->api = $services->get('Omeka\ApiManager');
        $this->settings = $services->get('Omeka\Settings');

        $schemeId = (int) $this->getArg('scheme');
        if (!$schemeId) {
            $this->logger->err(
                'No thesaurus specified.' // @translate
            );
            return;
        }

        $fill = $this->getArg('fill') ?: [];
        if (empty($fill['descriptor'])
            && empty($fill['path'])
        ) {
            $this->logger->err(
                'A preferred label with the descriptor or the full path is required to fill concepts.' // @translate
            );
            return;
        }

        if (empty($fill['path'])
            && empty($fill['ascendance'])
        ) {
            $this->logger->warn(
                'No defined property for path or ascendance, so nothing to fill.' // @translate
            );
            return;
        }

        $scheme = $this->api->search('items', ['id' => $schemeId])->getContent();
        if (!$scheme) {
            $this->logger->err(
                'Thesaurus #{item_id} not found.', // @translate
                ['item_id' => $schemeId]
            );
            return;
        }
        $scheme = reset($scheme);

        /** @var \Thesaurus\Stdlib\Thesaurus $thesaurus */
        $thesaurusLib = $services->get('Thesaurus\Thesaurus');
        $thesaurus = $thesaurusLib($scheme);
        if (!$thesaurus->isSkos()) {
            $this->logger->err(
                'Item #{item_id} is not a thesaurus.', // @translate
                 ['item_id' => $schemeId]
            );
            return;
        }

        $modes = [
            'replace',
            'prepend',
            'append',
            'remove',
        ];
        $mode = $this->getArg('mode') ?: 'replace';
        if (!in_array($mode, $modes)) {
            $this->logger->err(
                'The mode "{mode}" is not supported.', // @translate
                ['mode' => $mode]
            );
            return;
        }

        $separator = $this->getArg('separator') ?? \Thesaurus\Module::SEPARATOR;

        $scheme = $thesaurus->scheme();
        $schemeId = $scheme->id();

        $flatTree = $thesaurus->flatTree();

        // Scalar fields cannot be returned < v4.1.
        $skosIds = [];
        /** @var \Omeka\Api\Representation\PropertyRepresentation[] $properties */
        $properties = $this->api->search('properties', ['vocabulary_prefix' => 'skos'])->getContent();
        foreach ($properties as $property) {
            $skosIds[$property->term()] = $property->id();
        }

        // Check properties in options one time.
        if (!empty($fill['descriptor']) && empty($skosIds[$fill['descriptor']])) {
            $this->logger->err(
                'The property "{property}" for descriptor is not managed.', // @translate
                ['property' => $fill['descriptor']]
            );
            return;
        }
        if (!empty($fill['path']) && empty($skosIds[$fill['path']])) {
            $this->logger->err(
                'The property "{property}" for path is not managed.', // @translate
                ['property' => $fill['path']]
            );
            return;
        }
        if (!empty($fill['ascendance']) && empty($skosIds[$fill['ascendance']])) {
            $this->logger->err(
                'The property "{property}" for ascendance is not managed.', // @translate
                ['property' => $fill['ascendance']]
            );
            return;
        }

        /** @var \Omeka\Api\Representation\ResourceTemplateRepresentation $conceptTemplate */
        $conceptTemplateId = (int) $this->settings->get('thesaurus_skos_concept_template_id');
        $conceptTemplate = $conceptTemplateId
            ? $this->api->read('resource_templates', ['id' => $conceptTemplateId])->getContent()
            : $this->api->read('resource_templates', ['label' => 'Thesaurus Concept'])->getContent();
        $titleProperty = $conceptTemplate->titleProperty();
        if (!$titleProperty) {
            $this->logger->err(
                'The property to use as a title is not set in the template Thesaurus Concept.' // @translate
            );
            return;
        }
        if ($titleProperty->term() !== 'skos:prefLabel') {
            $this->logger->err(
                'To update data, the property used as a title in the template "Thesaurus Concept" must be "skos:prefLabel", not "{property}".', // @translate
                ['property' => $titleProperty->term()]
            );
            return;
        }
        if ($fill['descriptor'] !== 'skos:prefLabel') {
            $this->logger->err(
                'To update data, the descriptor must be the preferred label, not "{property}".', // @translate
                ['property' => $fill['descriptor']]
            );
            return;
        }

        $this->logger->notice(
            'Updating {count} descriptors.', // @translate
            ['count' => count($flatTree)]
        );

        // Don't update descriptor, but keep it.
        unset($fill['descriptor']);

        $totalProcessed = 0;
        foreach (array_chunk($flatTree, self::BATCH_SIZE, true) as $chunk) {
            if ($this->shouldStop()) {
                $this->logger->warn(
                    'The job  was stopped. {count}/{total} descriptors processed.', // @translate
                    ['count' => $totalProcessed, 'total' => count($flatTree)]
                );
                return;
            }

            if ($totalProcessed) {
                $this->logger->info(
                    '{count}/{total} descriptors processed.', // @translate
                    ['count' => $totalProcessed, 'total' => count($flatTree)]
                );

                // Avoid a speed and memory issue.
                $this->entityManager->clear();
            }

            foreach ($chunk as $conceptId => $itemData) {
                $item = $thesaurus->itemFromData($itemData);

                $ascendance = $thesaurus->setItem($item)->ascendants(true);
                $ascendanceTitles = array_column($ascendance, 'title', 'id');

                $data = json_decode(json_encode($item), true);

                // TODO Remove path when mode is remove/replace and fill option is empty.
                if (!empty($fill['path'])) {
                    $term = $fill['path'];
                    $val = [
                        'type' => 'literal',
                        'property_id' => $skosIds[$term],
                        '@value' => count($ascendanceTitles)
                            ? implode($separator, $ascendanceTitles) . $separator . $itemData['self']['title']
                            : $itemData['self']['title'],
                    ];
                    if ($mode === 'remove') {
                        unset($data[$term]);
                    } elseif ($mode === 'replace') {
                        $data[$term] = [$val];
                    } elseif ($mode === 'prepend') {
                        array_unshift($data[$term], $val);
                    } elseif ($mode === 'append') {
                        $data[$term][] = $val;
                    }
                }

                // TODO Remove ascendance when mode is remove/replace and fill option is empty.
                if (!empty($fill['ascendance'])) {
                    $term = $fill['ascendance'];
                    if (count($ascendance)) {
                        $val = [
                            'type' => 'literal',
                            'property_id' => $skosIds[$term],
                            '@value' => implode($separator, $ascendanceTitles),
                        ];
                        if ($mode === 'remove') {
                            unset($data[$term]);
                        } elseif ($mode === 'replace') {
                            $data[$term] = [$val];
                        } elseif ($mode === 'prepend') {
                            array_unshift($data[$term], $val);
                        } elseif ($mode === 'append') {
                            $data[$term][] = $val;
                        }
                    } elseif ($mode === 'remove' || $mode === 'replace') {
                        unset($data[$term]);
                    }
                }

                // To avoid issues with doctrine, remove owner, class and
                // template. They are kept anyway because update is partial.
                unset($data['o:owner'], $data['o:resource_template'], $data['o:resource_class']);
                $this->api->update('items', ['id' => $conceptId], $data, [], ['isPartial' => true]);

                ++$totalProcessed;
            }
        }

        // Args are same (just need scheme).
        $indexing = new \Thesaurus\Job\IndexThesaurus($this->job, $services);
        $indexing->perform();

        $this->logger->notice(
            'Concepts were updated and reindexed for thesaurus "{title}" (#{item_id}).', // @translate
            ['title' => $scheme->displayTitle(), 'item_id' => $schemeId]
        );
    }
}
