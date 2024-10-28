<?php declare(strict_types=1);

namespace Thesaurus\Job;

use Omeka\Job\AbstractJob;

class CreateThesaurus extends AbstractJob
{
    /**
     * Remove trailing punctuation.
     *
     * @var string
     */
    const TRIM_PUNCTUATION = " \n\r\t\v\x00.,-?!:;";

    /**
     * @var \Omeka\Api\Manager
     */
    protected $api;

    /**
     * @var \Common\Stdlib\EasyMeta
     */
    protected $easyMeta;

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
        $referenceIdProcessor->setReferenceId('thesaurus/structure/job_' . $this->job->getId());

        $this->logger = $services->get('Omeka\Logger');
        $this->logger->addProcessor($referenceIdProcessor);

        $this->api = $services->get('Omeka\ApiManager');
        $this->settings = $services->get('Omeka\Settings');
        $this->easyMeta = $services->get('Common\EasyMeta');
        $this->entityManager = $services->get('Omeka\EntityManager');

        $hasError = false;

        $name = $this->getArg('name');
        if (!$name) {
            $this->logger->err('A name is required to create a thesaurus.'); // @translate
            $hasError = true;
        }

        $formats = [
            'tab_offset',
            'tab_offset_code_prepended',
            'tab_offset_code_appended',
            'structure_label',
        ];

        $format = $this->getArg('format');
        $format = in_array($format, $formats) ? $format : null;
        if (!$format) {
            $this->logger->err('The format of the file is undetermined.'); // @translate
            $hasError = true;
        }

        $input = $this->getArg('input');
        if ($input && $this->getArg('skip_first_line')) {
            unset($input[key($input)]);
        }
        if (!$input) {
            $this->logger->err('A list of concepts is required to create a thesaurus.'); // @translate
            $hasError = true;
        }

        // The descriptor is required.
        $fill = $this->getArg('fill') ?: [];
        if (empty($fill['descriptor'])
            && empty($fill['path'])
        ) {
            $this->logger->err('A preferred label with the descriptor or the full path is required to fill concepts.'); // @translate
            $hasError = true;
        }

        // TODO Copy/Move the checks from the controller to the job.
        if ($format === 'tab_offset_code_prepended' || $format === 'tab_offset_code_appended') {
            $valueCodes = $this->getArg('codes') ?: [];
            if (!$valueCodes) {
                $this->logger->err(
                    'The input format is defined as containing codes, but no codes are defined.' // @translate
                );
                $hasError = true;
            }
        }

        if ($hasError) {
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            return;
        }

        $separator = $this->getArg('separator') ?? \Thesaurus\Module::SEPARATOR;

        $clean = $this->getArg('clean') ?? [
            'trim_punctuation',
        ];

        // Prepare resource classes and templates.
        $ownerId = $this->job->getOwner()->getId();
        $skosVocabulary = $this->api->read('vocabularies', ['prefix' => 'skos'])->getContent();
        $schemeClass = $this->api->read('resource_classes', ['vocabulary' => $skosVocabulary->id(), 'localName' => 'ConceptScheme'])->getContent();
        $conceptClass = $this->api->read('resource_classes', ['vocabulary' => $skosVocabulary->id(), 'localName' => 'Concept'])->getContent();
        $schemeTemplateId = (int) $this->settings->get('thesaurus_skos_scheme_template_id');
        $schemeTemplate = $schemeTemplateId
            ? $this->api->read('resource_templates', ['id' => $schemeTemplateId])->getContent()
            : $this->api->read('resource_templates', ['label' => 'Thesaurus Scheme'])->getContent();
        $conceptTemplateId = (int) $this->settings->get('thesaurus_skos_concept_template_id');
        $conceptTemplate = $conceptTemplateId
            ? $this->api->read('resource_templates', ['id' => $conceptTemplateId])->getContent()
            : $this->api->read('resource_templates', ['label' => 'Thesaurus Concept'])->getContent();
        $collectionClass = $this->api->read('resource_classes', ['vocabulary' => $skosVocabulary->id(), 'localName' => 'Collection'])->getContent();

        $properties = $this->easyMeta->propertyIds();

        // Check properties in options one time.
        if (!empty($fill['descriptor']) && !isset($properties[$fill['descriptor']])) {
            $this->logger->err(
                'The property {property} for descriptor is not managed.', // @translate
                ['property' => $fill['descriptor']]
            );
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            return;
        }
        if (!empty($fill['path']) && empty($properties[$fill['path']])) {
            $this->logger->err(
                'The property "{property}" for path is not managed.', // @translate
                ['property' => $fill['path']]
            );
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            return;
        }
        if (!empty($fill['ascendance']) && empty($properties[$fill['ascendance']])) {
            $this->logger->err(
                'The property "{property}" for ascendance is not managed.', // @translate
                ['property' => $fill['ascendance']]
            );
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            return;
        }

        $this->logger->notice(
            'Processing {count} descriptors in three steps.', // @translate
            ['count' => count($input)]
        );

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
                    'property_id' => $properties['skos:prefLabel'],
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

        // TODO Ideally, do one or two loops to get ids and data and set temp ids for relations and to append data and a last loop for api in order to avoid the updates.

        $this->logger->notice(
            'Step 1/3: creation of {count} descriptors.', // @translate
            ['count' => count($input)]
        );

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
                    'property_id' => $properties['skos:inScheme'],
                    'value_resource_id' => $schemeId,
                ],
            ],
        ];

        if ($format === 'tab_offset') {
            $result = $this->convertThesaurusTabOffset($input, $baseConcept, $fill, $separator, $clean);
        } elseif ($format === 'structure_label') {
            $result = $this->convertThesaurusStructureLabel($input, $baseConcept, $fill, $separator, $clean);
        } elseif ($format === 'tab_offset_code_prepended' || $format === 'tab_offset_code_appended') {
            $result = $this->convertThesaurusTabOffset($input, $baseConcept, $fill, $separator, $clean, $format === 'tab_offset_code_appended' ? 'appended' : 'prepended');
        }

        // Even if the job is stopped, fill the other data.

        $topIds = $result['topIds'];
        $narrowers = $result['narrowers'];
        $narrowers = array_filter($narrowers);

        $this->logger->notice(
            'Step 2/3: creation of relations between descriptors.' // @translate
        );

        // Fourth, append narrower concepts to concepts.
        $totalProcessed = 0;
        foreach ($narrowers as $parentId => $narrowerIds) {
            if ($totalProcessed && ($totalProcessed % 100) === 0) {
                $this->logger->info(
                    '{count}/{total} descriptors completed.', // @translate
                    ['count' => $totalProcessed, 'total' => count($narrowers)]
                );

                $this->entityManager->clear();
                $this->entityManager->getRepository(\Omeka\Entity\User::class)->find($ownerId);
            }

            $concept = $this->api->read('items', ['id' => $parentId])->getContent();
            $conceptJson = json_decode(json_encode($concept), true);
            foreach ($narrowerIds as $narrowerId) {
                $conceptJson['skos:narrower'][] = [
                    'type' => 'resource:item',
                    'property_id' => $properties['skos:narrower'],
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
                    'property_id' => $properties['skos:hasTopConcept'],
                    'value_resource_id' => $topId,
                ];
            }
            $this->api->update('items', $schemeId, $schemeJson, [], ['isPartial' => true]);
        }

        $this->logger->notice(
            'Step 3/3: indexation of new thesaurus.' // @translate
        );

        // The scheme is needed for job Indexing.
        $args = $this->job->getArgs();
        $args['scheme'] = $schemeId;
        $this->job->setArgs($args);
        $indexing = new \Thesaurus\Job\IndexThesaurus($this->job, $services);
        $indexing->perform();

        $this->logger->notice(
            'The thesaurus "{name}" is ready, with {count} descriptors.', // @translate
            ['name' => ucfirst($name), 'count' => count($input)]
        );
    }

    /**
     * Convert a structured list into a flat thesaurus from format "tab offset".
     */
    protected function convertThesaurusTabOffset(
        array $lines,
        array $baseConcept,
        array $fill,
        string $separator,
        array $clean,
        ?string $isCodePrependedOrAppended = null
    ): array {
        $schemeId = $baseConcept['skos:inScheme'][0]['value_resource_id'];
        $ownerId = $baseConcept['o:owner']['o:id'];

        $topIds = [];
        $narrowers = [];

        $fillPropertyIds = [
            'descriptor' => empty($fill['descriptor']) ? null : $this->easyMeta->propertyId($fill['descriptor']),
            'path' => empty($fill['path']) ? null : $this->easyMeta->propertyId($fill['path']),
            'ascendance' => empty($fill['ascendance']) ? null : $this->easyMeta->propertyId($fill['ascendance']),
        ];

        $isCodePrepended = $isCodePrependedOrAppended === 'prepended';
        $isCodeAppended = $isCodePrependedOrAppended === 'appended';
        $hasCode = $isCodePrepended || $isCodeAppended;

        $codesToProperties = [];
        if ($hasCode) {
            // Fill missing terms with the code.
            foreach ($this->getArg('codes') ?: [] as $code => $term) {
                $codesToProperties[$code] = $term ?: $code;
            }
        }

        // First loop to build descriptors with additional data and second loop to save.
        // This is a quick step, there is no api call.
        $initialData = [];

        /** @var \Omeka\Api\Representation\ItemRepresentation $previousConcept */

        $levels = [];
        $ascendance = [];
        $totalProcessed = 0;
        $conceptIndex = null;
        $previousConceptIndex = null;
        $levelConcepts = [];
        foreach ($lines as $line) {
            $descriptor = trim($line);
            // Replace entities first to avoid to break html entities.
            // TODO The "@" avoids the deprecation notice. Replace by html_entity_decode/htmlentities.
            $descriptor = trim((string) @mb_convert_encoding($descriptor, 'UTF-8', 'HTML-ENTITIES'));

            $propertyTerm = 'descriptor';
            if ($hasCode) {
                if ($isCodeAppended) {
                    $codeToCheck = mb_strpos($descriptor, ' ') === false? null : trim(mb_strrchr($descriptor, ' '));
                } else {
                    $codeToCheck = mb_strpos($descriptor, ' ') === false? null : strtok(trim($descriptor), ' ');
                }
                if (isset($codesToProperties[$codeToCheck])) {
                    $propertyTerm = $codesToProperties[$codeToCheck];
                    $descriptor = $isCodeAppended
                        ? trim(mb_substr($descriptor, 0, - mb_strlen($codeToCheck)))
                        : trim(mb_substr($descriptor,  mb_strlen($codeToCheck)));
                }
            }

            $descriptor = $this->trimAndCleanString($descriptor, $clean);
            if (!strlen($descriptor)) {
                continue;
            }

            // Data about the previous descriptor.
            if ($propertyTerm !== 'descriptor') {
                if (!$previousConceptIndex) {
                    $this->logger->warn(
                        'The line "{string} has the code "{code}", but there is no previous descriptor to apply to it. It is skipped.', // @translate
                        ['string' => trim($line), 'code' => $codeToCheck]
                    );
                    continue;
                }
                $initialData[$previousConceptIndex][$propertyTerm][] = [
                    'type' => 'literal',
                    'property_id' => $this->easyMeta->propertyId($propertyTerm),
                    '@value' => $descriptor,
                ];
                continue;
            }

            $data = $baseConcept;

            $line = rtrim($line);
            $level = strrpos($line, "\t");
            $level = $level === false ? 0 : ++$level;
            if (!$level) {
                $ascendance = [];
            }

            if ($fillPropertyIds['descriptor']) {
                $data[$fill['descriptor']][] = [
                    'type' => 'literal',
                    'property_id' => $fillPropertyIds['descriptor'],
                    '@value' => $descriptor,
                ];
            }

            if ($fillPropertyIds['path']) {
                $data[$fill['path']][] = [
                    'type' => 'literal',
                    'property_id' => $fillPropertyIds['path'],
                    '@value' => $level && count($ascendance)
                        ? implode($separator, array_slice($ascendance, 0, $level)) . $separator . $descriptor
                        : $descriptor,
                ];
            }

            if ($level && count($ascendance) && $fillPropertyIds['ascendance']) {
                $data[$fill['ascendance']][] = [
                    'type' => 'literal',
                    'property_id' => $fillPropertyIds['ascendance'],
                    '@value' => implode($separator, array_slice($ascendance, 0, $level)),
                ];
            }

            if (!$level) {
                $levels = [];
                $ascendance = [];
            }

            // Prepend a letter to make a clear distinction with concept id.
            $newConceptIndex = 'c' . ++$conceptIndex;
            $previousConceptIndex = $newConceptIndex;

            // Store the current concept, except relations.
            $initialData[$newConceptIndex] = $data;
            $levelConcepts[$newConceptIndex] = $level;

            // Store the data to create path and ascendance when needed.
            $levels[$level] = $newConceptIndex;
            $ascendance[$level] = $descriptor;
        }

        // Second loop to save descriptors, updating relations with real id.
        // The input thesaurus must be in right order.
        $levels = [];
        $ascendance = [];
        $totalProcessed = 0;
        foreach ($initialData as $conceptIndex => $data) {
            if ($this->shouldStop()) {
                $this->logger->warn(
                    'The job was stopped. {count}/{total} lines processed.', // @translate
                    ['count' => $totalProcessed, 'total' => count($lines)]
                );
                return [
                    'topIds' => $topIds,
                    'narrowers' => $narrowers,
                ];
            }

            if ($totalProcessed && ($totalProcessed % 100) === 0) {
                $this->logger->info(
                    '{count}/{total} descriptors processed.', // @translate
                    ['count' => $totalProcessed, 'total' => count($lines)]
                );

                $this->entityManager->clear();
                $this->entityManager->getRepository(\Omeka\Entity\User::class)->find($ownerId);
            }

            $level = $levelConcepts[$conceptIndex];
            $parentLevel = $level ? $level - 1 : false;
            if (!$level) {
                $ascendance = [];
            }

            if ($level) {
                $data['skos:broader'] = [
                    [
                        'type' => 'resource:item',
                        'property_id' => $this->easyMeta->propertyId('skos:broader'),
                        'value_resource_id' => $levels[$parentLevel],
                    ],
                ];
            } else {
                $levels = [];
                $ascendance = [];
                $data['skos:topConceptOf'] = [
                    [
                        'type' => 'resource:item',
                        'property_id' => $this->easyMeta->propertyId('skos:topConceptOf'),
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
        array $fill,
        string $separator,
        array $clean
    ): array {
        $schemeId = $baseConcept['skos:inScheme'][0]['value_resource_id'];
        $ownerId = $baseConcept['o:owner']['o:id'];

        $topIds = [];
        $narrowers = [];

        $fillPropertyIds = [
            'descriptor' => empty($fill['descriptor']) ? null : $this->easyMeta->propertyId($fill['descriptor']),
            'path' => empty($fill['path']) ? null : $this->easyMeta->propertyId($fill['path']),
            'ascendance' => empty($fill['ascendance']) ? null : $this->easyMeta->propertyId($fill['ascendance']),
        ];

        $trimPunctuation = in_array('trim_punctuation', $clean);

        $sep = '-';

        // First, prepare a key-value array. The key should be a string.
        $input = [];
        foreach ($lines as $line) {
            [$structure, $descriptor] = array_map('trim', (explode(' ', $line . ' ', 2)));
            // TODO The "@" avoids the deprecation notice. Replace by html_entity_decode/htmlentities.
            $structure = trim((string) @mb_convert_encoding($structure, 'UTF-8', 'HTML-ENTITIES'));
            $descriptor = trim((string) @mb_convert_encoding($descriptor, 'UTF-8', 'HTML-ENTITIES'));
            if ($trimPunctuation) {
                $structure = trim($structure, self::TRIM_PUNCTUATION);
            }
            $descriptor = $this->trimAndCleanString($descriptor, $clean);
            if (!strlen($descriptor)) {
                continue;
            }
            $input[(string) $structure] = $descriptor;
        }
        $input = array_filter($input);

        if (count($input) !== count($lines)) {
            $this->logger->notice(
                'After first step, {count} descriptors can be processed.', // @translate
                ['count' => count($input)]
            );
        }

        // Second, prepare each row.
        $levels = [];
        $ascendance = [];
        $totalProcessed = 0;
        foreach ($input as $structure => $descriptor) {
            if ($this->shouldStop()) {
                $this->logger->warn(
                    'The job  was stopped. {count}/{total} descriptors processed.', // @translate
                    ['count' => $totalProcessed, 'total' => count($input)]
                );
                return [
                    'topIds' => $topIds,
                    'narrowers' => $narrowers,
                ];
            }

            if ($totalProcessed && ($totalProcessed % 100) === 0) {
                $this->logger->info(
                    '{count}/{total} descriptors processed.', // @translate
                    ['count' => $totalProcessed, 'total' => count($input)]
                );

                $this->entityManager->clear();
                $this->entityManager->getRepository(\Omeka\Entity\User::class)->find($ownerId);
            }

            $level = substr_count((string) $structure, $sep);
            $parentLevel = $level ? $level - 1 : false;
            if (!$level) {
                $ascendance = [];
            }

            $data = $baseConcept;

            if ($fillPropertyIds['descriptor']) {
                $data[$fill['descriptor']][] = [
                    'type' => 'literal',
                    'property_id' => $fillPropertyIds['descriptor'],
                    '@value' => $descriptor,
                ];
            }
            if ($fillPropertyIds['path']) {
                $data[$fill['path']][] = [
                    'type' => 'literal',
                    'property_id' => $fillPropertyIds['path'],
                    '@value' => $level && count($ascendance)
                        ? implode($separator, array_slice($ascendance, 0, $level)) . $separator . $descriptor
                        : $descriptor,
                ];
            }
            if ($level && count($ascendance) && $fillPropertyIds['ascendance']) {
                $data[$fill['ascendance']][] = [
                    'type' => 'literal',
                    'property_id' => $fillPropertyIds['ascendance'],
                    '@value' => implode($separator, array_slice($ascendance, 0, $level)),
                ];
            }

            $data['skos:notation'][] = [
                'type' => 'literal',
                'property_id' => $this->easyMeta->propertyId('skos:notation'),
                '@value' => $structure,
            ];

            if ($level) {
                $data['skos:broader'] = [
                    [
                        'type' => 'resource:item',
                        'property_id' => $this->easyMeta->propertyId('skos:broader'),
                        'value_resource_id' => $levels[$parentLevel],
                    ],
                ];
            } else {
                $levels = [];
                $ascendance = [];
                $data['skos:topConceptOf'] = [
                    [
                        'type' => 'resource:item',
                        'property_id' => $this->easyMeta->propertyId('skos:topConceptOf'),
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
     * Trim and clean string according to options.
     */
    protected function trimAndCleanString($string, array $params): string
    {
        $string = trim((string) $string);
        if (in_array('trim_punctuation', $params)) {
            $string = trim($string, self::TRIM_PUNCTUATION);
        }
        if (in_array('apostrophe', $params)) {
            $string = str_replace("'", '’', $string);
        }
        if (in_array('single_quote', $params)) {
            $string = str_replace('’', "'", $string);
        }
        if (in_array('lowercase', $params)) {
            $string = mb_strtolower($string);
        }
        if (in_array('ucfirst', $params)) {
            $string = ucfirst(mb_strtolower($string));
        }
        if (in_array('ucwords', $params)) {
            $string = ucwords(mb_strtolower($string));
        }
        if (in_array('uppercase', $params)) {
            $string = mb_strtoupper($string);
        }
        return $string;
    }
}
