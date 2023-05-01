<?php declare(strict_types=1);

namespace Thesaurus\Job;

use Omeka\Job\AbstractJob;
use Omeka\Stdlib\Message;

class CreateThesaurus extends AbstractJob
{
    /**
     * Remove trailing punctuation.
     *
     * @var string
     */
    const TRIM_PUNCTUATION = " \n\r\t\v\x00.,-?!:;";

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
        $referenceIdProcessor->setReferenceId('thesaurus/structure/job_' . $this->job->getId());

        $this->logger = $services->get('Omeka\Logger');
        $this->logger->addProcessor($referenceIdProcessor);

        $this->entityManager = $services->get('Omeka\EntityManager');

        $name = $this->getArg('name');
        if (!$name) {
            $this->logger->err('A name is required to create a thesaurus.'); // @translate
        }

        $format = $this->getArg('format');
        $format = in_array($format, ['tab_offset', 'structure_label']) ? $format : null;
        if (!$format) {
            $this->logger->err('The format of the file is undetermined.'); // @translate
        }

        $input = $this->getArg('input');
        if (!$input) {
            $this->logger->err('A list of concepts is required to create a thesaurus.'); // @translate
        }

        if (!$name || !$format || !$input) {
            return;
        }

        // The descriptor is required.
        $fill = $this->getArg('fill') ?: [];
        if (empty($fill['descriptor'])
            && empty($fill['path'])
        ) {
            $this->logger->err('A preferred label with the descriptor or the full path is required to fill concepts.'); // @translate
            return;
        }

        $separator = $this->getArg('separator') ?? \Thesaurus\Module::SEPARATOR;

        $clean = $this->getArg('clean') ?? [
            'trim_punctuation',
        ];

        $this->api = $services->get('Omeka\ApiManager');

        // Prepare resource classes and templates.
        $ownerId = $this->job->getOwner()->getId();
        $skosVocabulary = $this->api->read('vocabularies', ['prefix' => 'skos'])->getContent();
        $schemeClass = $this->api->read('resource_classes', ['vocabulary' => $skosVocabulary->id(), 'localName' => 'ConceptScheme'])->getContent();
        $conceptClass = $this->api->read('resource_classes', ['vocabulary' => $skosVocabulary->id(), 'localName' => 'Concept'])->getContent();
        $schemeTemplate = $this->api->read('resource_templates', ['label' => 'Thesaurus Scheme'])->getContent();
        $conceptTemplate = $this->api->read('resource_templates', ['label' => 'Thesaurus Concept'])->getContent();
        $collectionClass = $this->api->read('resource_classes', ['vocabulary' => $skosVocabulary->id(), 'localName' => 'Collection'])->getContent();

        // Scalar fields cannot be returned < v4.1.
        $skosIds = [];
        /** @var \Omeka\Api\Representation\PropertyRepresentation[] $properties */
        $properties = $this->api->search('properties', ['vocabulary_prefix' => 'skos'])->getContent();
        foreach ($properties as $property) {
            $skosIds[$property->term()] = $property->id();
        }

        // Check properties in options one time.
        if (!empty($fill['descriptor']) && empty($skosIds[$fill['descriptor']])) {
            $this->logger->err(new Message(
                'The property "%1$s" for descriptor is not managed.', // @translate
                $fill['descriptor']
            ));
            return;
        }
        if (!empty($fill['path']) && empty($skosIds[$fill['path']])) {
            $this->logger->err(new Message(
                'The property "%1$s" for path is not managed.', // @translate
                $fill['path']
            ));
            return;
        }
        if (!empty($fill['ascendance']) && empty($skosIds[$fill['ascendance']])) {
            $this->logger->err(new Message(
                'The property "%1$s" for ascendance is not managed.', // @translate
                $fill['ascendance']
            ));
            return;
        }

        $this->logger->notice(new Message(
            'Processing %d descriptors in three steps.', // @translate
            count($input)
        ));

        // First create the item set.
        $data = [
            'o:owner' => ['o:id' => $ownerId],
            'o:resource_class' => ['o:id' => $collectionClass->id()],
            'dcterms:title' => [
                [
                    'type' => 'literal',
                    'property_id' => 1,
                    '@value' => ucfirst($name),
                ],
            ],
            'dcterms:identifier' => [
                [
                    'type' => 'literal',
                    'property_id' => 10,
                    '@value' => 'c' . $name,
                ],
            ],
        ];
        /** @var \Omeka\Api\Representation\ItemSetRepresentation $itemSet */
        $itemSet = $this->api->create('item_sets', $data)->getContent();

        // Second, create the scheme.
        $data = [
            'o:owner' => ['o:id' => $ownerId],
            'o:resource_class' => ['o:id' => $schemeClass->id()],
            'o:resource_template' => ['o:id' => $schemeTemplate->id()],
            'o:item_set' => [
                ['o:id' => $itemSet->id()],
            ],
            'skos:prefLabel' => [
                [
                    'type' => 'literal',
                    'property_id' => $skosIds['skos:prefLabel'],
                    '@value' => ucfirst($name),
                ],
            ],
            'dcterms:identifier' => [
                [
                    'type' => 'literal',
                    'property_id' => 10,
                    '@value' => $name,
                ],
            ],
        ];
        /** @var \Omeka\Api\Representation\ItemRepresentation $scheme */
        $scheme = $this->api->create('items', $data)->getContent();
        $schemeId = $scheme->id();

        // Third, create each item one by one to set tree.

        $this->logger->notice(new Message(
            'Step 1/3: creation of %d descriptors.', // @translate
            count($input)
        ));

        $baseConcept = [
            'o:owner' => ['o:id' => $ownerId],
            'o:resource_class' => ['o:id' => $conceptClass->id()],
            'o:resource_template' => ['o:id' => $conceptTemplate->id()],
            'o:item_set' => [
                ['o:id' => $itemSet->id()],
            ],
            'skos:inScheme' => [
                [
                    'type' => 'resource:item',
                    'property_id' => $skosIds['skos:inScheme'],
                    'value_resource_id' => $schemeId,
                ],
            ],
        ];

        if ($format === 'tab_offset') {
            $result = $this->convertThesaurusTabOffset($input, $baseConcept, $skosIds, $fill, $separator, $clean);
        } elseif ($format === 'structure_label') {
            $result = $this->convertThesaurusStructureLabel($input, $baseConcept, $skosIds, $fill, $separator, $clean);
        }

        // Even if the job is stopped, fill the other data.

        $topIds = $result['topIds'];
        $narrowers = $result['narrowers'];
        $narrowers = array_filter($narrowers);

        $this->logger->notice(new Message(
            'Step 2/3: creation of relations between descriptors.' // @translate
        ));

        // Fourth, append narrower concepts to concepts.
        $totalProcessed = 0;
        foreach ($narrowers as $parentId => $narrowerIds) {
            if ($totalProcessed && ($totalProcessed % 100) === 0) {
                $this->logger->info(new Message(
                    '%1$d/%2$d descriptors completed.', // @translate
                    $totalProcessed, count($narrowers)
                ));

                $this->entityManager->clear();
                $this->entityManager->getRepository(\Omeka\Entity\User::class)->find($ownerId);
            }

            $concept = $this->api->read('items', ['id' => $parentId])->getContent();
            $conceptJson = json_decode(json_encode($concept), true);
            foreach ($narrowerIds as $narrowerId) {
                $conceptJson['skos:narrower'][] = [
                    'type' => 'resource:item',
                    'property_id' => $skosIds['skos:narrower'],
                    'value_resource_id' => $narrowerId,
                ];
            }
            $this->api->update('items', $parentId, $conceptJson, [], ['isPartial' => true]);

            ++$totalProcessed;
        }

        // Fifth, append top concepts to scheme.
        if ($topIds) {
            $schemeJson = json_decode(json_encode($scheme), true);
            foreach ($topIds as $topId) {
                $schemeJson['skos:hasTopConcept'][] = [
                    'type' => 'resource:item',
                    'property_id' => $skosIds['skos:hasTopConcept'],
                    'value_resource_id' => $topId,
                ];
            }
            $this->api->update('items', $schemeId, $schemeJson, [], ['isPartial' => true]);
        }

        $this->logger->notice(new Message(
            'Step 3/3: indexation of new thesaurus.' // @translate
        ));

        // The scheme is needed for job Indexing.
        $args = $this->job->getArgs();
        $args['scheme'] = $schemeId;
        $this->job->setArgs($args);
        $indexing = new \Thesaurus\Job\Indexing($this->job, $services);
        $indexing->perform();

        $message = new Message(
            'The thesaurus "%1$s" is ready, with %2$d descriptors.', // @translate
            ucfirst($name), count($input)
        );
        $this->logger->notice($message);
    }

    /**
     * Convert a flat list into a flat thesaurus from format "tab offset".
     */
    protected function convertThesaurusTabOffset(
        array $lines,
        array $baseConcept,
        array $skosIds,
        array $fill,
        string $separator,
        array $clean
    ): array {
        $schemeId = $baseConcept['skos:inScheme'][0]['value_resource_id'];
        $ownerId = $baseConcept['o:owner']['o:id'];

        $topIds = [];
        $narrowers = [];

        $trimPunctuation = in_array('trim_punctuation', $clean);

        $levels = [];
        $ascendance = [];
        $totalProcessed = 0;
        foreach ($lines as $line) {
            if ($this->shouldStop()) {
                $this->logger->warn(new Message(
                    'The job  was stopped. %1$d/%2$d descriptors processed.', // @translate
                    $totalProcessed, count($lines)
                ));
                return [
                    'topIds' => $topIds,
                    'narrowers' => $narrowers,
                ];
            }

            if ($totalProcessed && ($totalProcessed % 100) === 0) {
                $this->logger->info(new Message(
                    '%1$d/%2$d descriptors processed.', // @translate
                    $totalProcessed, count($lines)
                ));

                $this->entityManager->clear();
                $this->entityManager->getRepository(\Omeka\Entity\User::class)->find($ownerId);
            }

            $descriptor = trim($line);
            // Replace entities first to avoid to break html entities.
            $descriptor = mb_convert_encoding($descriptor, 'UTF-8', 'HTML-ENTITIES');
            if ($trimPunctuation) {
                $descriptor = trim($descriptor, self::TRIM_PUNCTUATION);
            }

            $line = rtrim($line);
            $level = strrpos($line, "\t");
            $level = $level === false ? 0 : ++$level;
            $parentLevel = $level ? $level - 1 : false;
            if (!$level) {
                $ascendance = [];
            }

            $data = $baseConcept;

            if (!empty($fill['descriptor']) && !empty($skosIds[$fill['descriptor']])) {
                $data[$fill['descriptor']][] = [
                    'type' => 'literal',
                    'property_id' => $skosIds[$fill['descriptor']],
                    '@value' => $descriptor,
                ];
            }

            if (!empty($fill['path'])) {
                $data[$fill['path']][] = [
                    'type' => 'literal',
                    'property_id' => $skosIds[$fill['path']],
                    '@value' => $level && count($ascendance)
                        ? implode($separator, array_slice($ascendance, 0, $level)) . $separator . $descriptor
                        : $descriptor,
                ];
            }

            if ($level && count($ascendance) && !empty($fill['ascendance'])) {
                $data[$fill['ascendance']][] = [
                    'type' => 'literal',
                    'property_id' => $skosIds[$fill['ascendance']],
                    '@value' => implode($separator, array_slice($ascendance, 0, $level)),
                ];
            }

            if ($level) {
                $data['skos:broader'] = [
                    [
                        'type' => 'resource:item',
                        'property_id' => $skosIds['skos:broader'],
                        'value_resource_id' => $levels[$parentLevel],
                    ],
                ];
            } else {
                $levels = [];
                $ascendance = [];
                $data['skos:topConceptOf'] = [
                    [
                        'type' => 'resource:item',
                        'property_id' => $skosIds['skos:topConceptOf'],
                        'value_resource_id' => $schemeId,
                    ],
                ];
            }

            $concept = $this->api->create('items', $data)->getContent();
            $conceptId = $concept->id();

            $levels[$level] = $conceptId;
            $ascendance[$level] = $descriptor;

            if ($level === 0) {
                $topIds[] = $conceptId;
            } else {
                $narrowers[$levels[$parentLevel]][] = $conceptId;
            }

            ++$totalProcessed;
        }

        return [
            'topIds' => $topIds,
            'narrowers' => $narrowers,
        ];
    }

    /**
     * Convert a flat list into a flat thesaurus from format "structure label".
     *
     * The input should be ordered and logical.
     *
     * 01          Europe
     * 01-01       France
     * 01-01-01    Paris
     * 01-02       United Kingdom
     * 01-02-01    England
     * 01-02-01-01 London
     * 02          Asia
     * 02-01       Japan
     * 02-01-01    Tokyo
     *
     * @todo Factorize with convertThesaurusTabOffset.
     */
    protected function convertThesaurusStructureLabel(
        array $lines,
        array $baseConcept,
        array $skosIds,
        array $fill,
        string $separator,
        array $clean
    ): array {
        $schemeId = $baseConcept['skos:inScheme'][0]['value_resource_id'];
        $ownerId = $baseConcept['o:owner']['o:id'];

        $topIds = [];
        $narrowers = [];

        $trimPunctuation = in_array('trim_punctuation', $clean);

        $sep = '-';

        // First, prepare a key-value array. The key should be a string.
        $input = [];
        foreach ($lines as $line) {
            [$structure, $descriptor] = array_map('trim', (explode(' ', $line . ' ', 2)));
            $structure = mb_convert_encoding($structure, 'UTF-8', 'HTML-ENTITIES');
            $descriptor = mb_convert_encoding($descriptor, 'UTF-8', 'HTML-ENTITIES');
            if ($trimPunctuation) {
                $structure = trim($structure, self::TRIM_PUNCTUATION);
                $descriptor = trim($descriptor, self::TRIM_PUNCTUATION);
            }
            $input[(string) $structure] = $descriptor;
        }
        $input = array_filter($input);

        if (count($input) !== count($lines)) {
            $this->logger->notice(new Message(
                'After first step, %1$d descriptors can be processed.', // @translate
                count($input)
            ));
        }

        // Second, prepare each row.
        $levels = [];
        $ascendance = [];
        $totalProcessed = 0;
        foreach ($input as $structure => $descriptor) {
            if ($this->shouldStop()) {
                $this->logger->warn(new Message(
                    'The job  was stopped. %1$d/%2$d descriptors processed.', // @translate
                    $totalProcessed, count($input)
                ));
                return [
                    'topIds' => $topIds,
                    'narrowers' => $narrowers,
                ];
            }

            if ($totalProcessed && ($totalProcessed % 100) === 0) {
                $this->logger->info(new Message(
                    '%1$d/%2$d descriptors processed.', // @translate
                    $totalProcessed, count($input)
                ));

                $this->entityManager->clear();
                $this->entityManager->getRepository(\Omeka\Entity\User::class)->find($ownerId);
            }

            $level = substr_count((string) $structure, $sep);
            $parentLevel = $level ? $level - 1 : false;
            if (!$level) {
                $ascendance = [];
            }

            $data = $baseConcept;

            if (!empty($fill['descriptor']) && !empty($skosIds[$fill['descriptor']])) {
                $data[$fill['descriptor']][] = [
                    'type' => 'literal',
                    'property_id' => $skosIds[$fill['descriptor']],
                    '@value' => $descriptor,
                ];
            }
            if (!empty($fill['path'])) {
                $data[$fill['path']][] = [
                    'type' => 'literal',
                    'property_id' => $skosIds[$fill['path']],
                    '@value' => $level && count($ascendance)
                        ? implode($separator, array_slice($ascendance, 0, $level)) . $separator . $descriptor
                        : $descriptor,
                ];
            }
            if ($level && count($ascendance)) {
                if (!empty($fill['ascendance'])) {
                    $data[$fill['ascendance']][] = [
                        'type' => 'literal',
                        'property_id' => $skosIds[$fill['ascendance']],
                        '@value' => implode($separator, array_slice($ascendance, 0, $level)),
                    ];
                }
            }

            $data['skos:notation'][] = [
                'type' => 'literal',
                'property_id' => $skosIds['skos:notation'],
                '@value' => $structure,
            ];

            if ($level) {
                $data['skos:broader'] = [
                    [
                        'type' => 'resource:item',
                        'property_id' => $skosIds['skos:broader'],
                        'value_resource_id' => $levels[$parentLevel],
                    ],
                ];
            } else {
                $levels = [];
                $ascendance = [];
                $data['skos:topConceptOf'] = [
                    [
                        'type' => 'resource:item',
                        'property_id' => $skosIds['skos:topConceptOf'],
                        'value_resource_id' => $schemeId,
                    ],
                ];
            }

            $concept = $this->api->create('items', $data)->getContent();
            $conceptId = $concept->id();

            $levels[$level] = $conceptId;
            $ascendance[$level] = $descriptor;

            if ($level === 0) {
                $topIds[] = $conceptId;
            } else {
                $narrowers[$levels[$parentLevel]][] = $conceptId;
            }

            ++$totalProcessed;
        }

        return [
            'topIds' => $topIds,
            'narrowers' => $narrowers,
        ];
    }
}
