<?php declare(strict_types=1);

namespace Thesaurus\Job;

use Omeka\Job\AbstractJob;
use Omeka\Stdlib\Message;
use Thesaurus\Mvc\Controller\Plugin\Thesaurus;

class UpdateConcepts extends AbstractJob
{
    /**
     * Limit for the loop to avoid heavy sql requests.
     *
     * @var int
     */
    const BATCH_SIZE = 100;

    /**
     * @var \Laminas\Log\Logger
     */
    protected $logger;

    /**
     * @var \Doctrine\ORM\EntityManager
     */
    protected $entityManager;

    /**
     * @var \Omeka\Api\Manager
     */
    protected $api;

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

        $schemeId = (int) $this->getArg('scheme');
        if (!$schemeId) {
            $this->logger->err(new Message(
                'No thesaurus specified.' // @translate
            ));
            return;
        }

        $fill = $this->getArg('fill') ?: [];
        if (empty($fill['descriptor'])
            && empty($fill['path'])
        ) {
            $this->logger->err('A preferred label with the descriptor or the full path is required to fill concepts.'); // @translate
            return;
        }

        if (empty($fill['path'])
            && empty($fill['ascendance'])
        ) {
            $this->logger->warn('No defined property for path or ascendance, so nothing to fill.'); // @translate
            return;
        }

        $scheme = $this->api->search('items', ['id' => $schemeId])->getContent();
        if (!$scheme) {
            $this->logger->err(new Message(
                'Thesaurus #%d not found.', // @translate
                $schemeId
            ));
            return;
        }
        $scheme = reset($scheme);

        /** @var \Thesaurus\Mvc\Controller\Plugin\Thesaurus $thesaurus */
        $thesaurusHelper = $services->get('ControllerPluginManager')->get('thesaurus');
        $thesaurus = $thesaurusHelper($scheme);
        if (!$thesaurus->isSkos()) {
            $this->logger->err(new Message(
                'Item #%d is not a thesaurus.', // @translate
                $schemeId
            ));
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
            $this->logger->err(new Message(
                'The mode "%s" is not supported.', // @translate
                $mode
            ));
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
            $this->logger->notice(new Message(
                'The property "%1$s" for descriptor is not managed.', // @translate
                $fill['descriptor']
            ));
            return;
        }
        if (!empty($fill['path']) && empty($skosIds[$fill['path']])) {
            $this->logger->notice(new Message(
                'The property "%1$s" for path is not managed.', // @translate
                $fill['path']
            ));
            return;
        }
        if (!empty($fill['ascendance']) && empty($skosIds[$fill['ascendance']])) {
            $this->logger->notice(new Message(
                'The property "%1$s" for ascendance is not managed.', // @translate
                $fill['ascendance']
            ));
            return;
        }

        $this->logger->notice(new Message(
            'Updating %d descriptors.', // @translate
            count($flatTree)
        ));

        // Don't update descriptor, but keep it.
        unset($fill['descriptor']);

        $totalProcessed = 0;
        foreach (array_chunk($flatTree, self::BATCH_SIZE, true) as $chunk) {
            if ($this->shouldStop()) {
                $this->logger->warn(new Message(
                    'The job  was stopped. %1$d/%2$d descriptors processed.', // @translate
                    $totalProcessed, count($flatTree)
                ));
                return;
            }

            if ($totalProcessed) {
                $this->logger->info(new Message(
                    '%1$d/%2$d descriptors processed.', // @translate
                    $totalProcessed, count($flatTree)
                ));

                // Avoid a speed and memory issue.
                $this->entityManager->clear();
            }

            foreach ($chunk as $conceptId => $itemData) {
                $update = false;
                $item = $thesaurus->itemFromData($itemData);
                $ascendance = $thesaurus->setItem($item)->ascendants(true);
                if (count($ascendance)) {
                    $data = json_decode(json_encode($item), true);
                    $ascendanceTitles = array_column($ascendance, 'title', 'id');
                    if (!empty($fill['path'])) {
                        $term = $fill['path'];
                        $update = true;
                        $val = [
                            'type' => 'literal',
                            'property_id' => $skosIds[$term],
                            '@value' => implode($separator, $ascendanceTitles) . $separator . $itemData['self']['title'],
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
                    if (!empty($fill['ascendance'])) {
                        $term = $fill['ascendance'];
                        $update = true;
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
                    }
                }
                if ($update) {
                    // To avoid issues with doctrine, remove owner, class and
                    // template. They are kept anyway because update is partial.
                    unset($data['o:owner'], $data['o:resource_template'], $data['o:resource_class']);
                    $this->api->update('items', ['id' => $conceptId], $data, [], ['isPartial' => true]);
                }
                ++$totalProcessed;
            }
        }

        $indexing = new \Thesaurus\Job\Indexing($this->job, $services);
        $indexing->perform();

        $message = new Message(
            'Concepts were updated and reindexed for thesaurus "%1$s" (#%2$d).', // @translate
            $scheme->displayTitle(), $schemeId
        );
        $this->logger->notice($message);
    }
}
