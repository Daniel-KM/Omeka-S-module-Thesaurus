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

        // The name is kept as is for the label, but the identifier is slugified
        // so it can be used in a clean url, whose pattern excludes any other
        // character (a name is generally built from the imported file name).
        $identifier = $this->slugify((string) $name);

        $formats = [
            'tab_offset',
            'tab_offset_code_prepended',
            'tab_offset_code_appended',
            'structure_label',
            'skos',
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
                    '@value' => mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1),
                ],
            ],
            'dcterms:identifier' => [
                [
                    'type' => 'literal',
                    'property_id' => 10,
                    '@value' => 'c' . $identifier,
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
                    '@value' => mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1),
                ],
            ],
            'dcterms:identifier' => [
                [
                    'type' => 'literal',
                    'property_id' => 10,
                    '@value' => $identifier,
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

        // A concept is a dedicated resource type: it belongs to a scheme (an
        // item) via the column "o:scheme", not to an item set. The value
        // skos:inScheme is kept for the rdf output and existing queries.
        $baseConcept = [
            'o:owner' => ['o:id' => $ownerId],
            'o:resource_class' => ['o:id' => $conceptClass->id()],
            'o:resource_template' => ['o:id' => $conceptTemplate->id()],
            'o:scheme' => ['o:id' => $schemeId],
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
        } elseif ($format === 'skos') {
            $result = $this->convertThesaurusSkos($input, $baseConcept, $fill);
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

            $concept = $this->api->read('concepts', ['id' => $parentId])->getContent();
            // TODO Don't use json_decode(json_encode()).
            $conceptJson = json_decode(json_encode($concept), true);
            foreach ($narrowerIds as $narrowerId) {
                $conceptJson['skos:narrower'][] = [
                    'type' => 'resource:concept',
                    'property_id' => $properties['skos:narrower'],
                    'value_resource_id' => $narrowerId,
                ];
            }
            $this->api->update('concepts', $parentId, $conceptJson, [], ['isPartial' => true]);

            ++$totalProcessed;
        }

        // Fifth, append top concepts to scheme.
        if ($topIds) {
            $schemeJson = json_decode(json_encode($scheme), true);
            foreach ($topIds as $topId) {
                $schemeJson['skos:hasTopConcept'][] = [
                    'type' => 'resource:concept',
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

        if ($this->getArg('create_customvocab')
            && class_exists('CustomVocab\Module', false)
        ) {
            $label = mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1);
            try {
                $this->api->create('custom_vocabs', [
                    'o:label' => $label,
                    'o:item_set' => ['o:id' => $itemSet->id()],
                ]);
                $this->logger->notice(
                    'The linked custom vocabulary "{label}" was created.', // @translate
                    ['label' => $label]
                );
            } catch (\Exception $e) {
                $this->logger->err(
                    'Unable to create the linked custom vocabulary "{label}": {message}', // @translate
                    ['label' => $label, 'message' => $e->getMessage()]
                );
            }
        }

        $this->logger->notice(
            'The thesaurus "{name}" is ready, with {count} descriptors.', // @translate
            ['name' => mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1), 'count' => count($input)]
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
            $descriptor = trim((string) mb_decode_numericentity($descriptor, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8'));

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
            $ascendance = array_slice($ascendance, 0, $level + 1);
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
                        'type' => 'resource:concept',
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

            $concept = $this->api->create('concepts', $data)->getContent();
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
     * Create the concepts of a thesaurus from a parsed SKOS structure.
     *
     * The input is the ordered list returned by the controller (level, uri,
     * label, path, prefLabels, values). All the values are imported according
     * to the "skos" job option (documentation, notation, relations, mappings,
     * other vocabularies, multilingual). The preferred labels and the hierarchy
     * are always imported.
     */
    protected function convertThesaurusSkos(array $input, array $baseConcept, array $fill): array
    {
        $services = $this->getServiceLocator();
        $schemeId = $baseConcept['skos:inScheme'][0]['value_resource_id'];

        $skosOptions = $this->getArg('skos') ?: [];
        $multilingual = in_array('multilingual', $skosOptions);
        $withDoc = in_array('documentation', $skosOptions);
        $withNotation = in_array('notation', $skosOptions);
        $withRelations = in_array('relations', $skosOptions);
        $withMappings = in_array('mappings', $skosOptions);
        $withOther = in_array('other_vocabularies', $skosOptions);

        // Admin language, normalized (fr_FR → fr) to match the rdf lang tags.
        $locale = (string) $services->get('Omeka\Settings')->get('locale');
        $adminLang = $locale === '' ? '' : strtolower(strtok($locale, '_-'));

        $descriptorTerm = $fill['descriptor'] ?: 'skos:prefLabel';
        $descriptorPid = $this->easyMeta->propertyId($descriptorTerm);
        $pathPid = empty($fill['path']) ? null : $this->easyMeta->propertyId($fill['path']);
        $ascendancePid = empty($fill['ascendance']) ? null : $this->easyMeta->propertyId($fill['ascendance']);
        $separator = $this->getArg('separator') ?? \Thesaurus\Module::SEPARATOR;

        // Map each property full uri to its Omeka id, since the rdf prefixes
        // may differ from the Omeka ones (e.g. EasyRdf shortens dcterms as
        // "dc").
        $uriToPid = $services->get('Omeka\Connection')->executeQuery(
            'SELECT CONCAT(vocabulary.namespace_uri, property.local_name) AS uri, property.id FROM property INNER JOIN vocabulary ON vocabulary.id = property.vocabulary_id'
        )->fetchAllKeyValue();

        $labelTerms = ['skos:altLabel' => true, 'skos:hiddenLabel' => true];
        $docTerms = array_flip([
            'skos:definition', 'skos:scopeNote', 'skos:note', 'skos:example',
            'skos:historyNote', 'skos:editorialNote', 'skos:changeNote',
        ]);
        $notationTerms = ['skos:notation' => true];
        $mappingTerms = array_flip([
            'skos:exactMatch', 'skos:closeMatch', 'skos:broadMatch',
            'skos:narrowMatch', 'skos:relatedMatch',
        ]);

        // Build value objects for a property from a list of {value, lang},
        // according to the multilingual option (else the admin language).
        $literals = function (array $items, int $pid) use ($multilingual, $adminLang): array {
            $out = [];
            $seen = [];
            foreach ($items as $it) {
                $rawLang = (string) ($it['lang'] ?? '');
                $lang = $rawLang === '' ? '' : strtolower((string) strtok($rawLang, '_-'));
                if (!$multilingual && $lang !== '' && $adminLang !== '' && $lang !== $adminLang) {
                    continue;
                }
                $key = $it['value'] . "\0" . ($multilingual ? $lang : '');
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $value = ['type' => 'literal', 'property_id' => $pid, '@value' => $it['value']];
                $useLang = $multilingual ? $lang : $adminLang;
                if ($useLang !== '') {
                    $value['@language'] = $useLang;
                }
                $out[] = $value;
            }
            return $out;
        };

        $topIds = [];
        $narrowers = [];
        $levels = [];
        $uriToId = [];
        $relatedByConcept = [];
        $missingProps = [];

        $total = count($input);
        $processed = 0;
        foreach ($input as $element) {
            if ($this->shouldStop()) {
                $this->logger->warn('The job was stopped. {count}/{total} concepts processed.', ['count' => $processed, 'total' => $total]); // @translate
                break;
            }
            if ($processed && ($processed % 100) === 0) {
                $this->logger->info('{count}/{total} concepts processed.', ['count' => $processed, 'total' => $total]); // @translate
                $this->entityManager->clear();
            }

            $level = (int) $element['level'];
            $data = $baseConcept;

            // Preferred label (descriptor).
            $data[$descriptorTerm] = $literals($element['prefLabels'] ?? [], $descriptorPid);
            if (!$data[$descriptorTerm] && !empty($element['label'])) {
                $value = ['type' => 'literal', 'property_id' => $descriptorPid, '@value' => $element['label']];
                if ($adminLang !== '') {
                    $value['@language'] = $adminLang;
                }
                $data[$descriptorTerm] = [$value];
            }

            // Path / ascendance.
            $path = $element['path'] ?? [];
            if ($ascendancePid && $path) {
                $data[$fill['ascendance']][] = ['type' => 'literal', 'property_id' => $ascendancePid, '@value' => implode($separator, $path)];
            }
            if ($pathPid) {
                $data[$fill['path']][] = ['type' => 'literal', 'property_id' => $pathPid, '@value' => implode($separator, array_merge($path, [$element['label'] ?? '']))];
            }

            // Other values, grouped by term.
            $byTerm = [];
            foreach (($element['values'] ?? []) as $val) {
                $byTerm[$val['term']][] = $val;
            }
            foreach ($byTerm as $term => $vals) {
                if ($term === 'skos:related') {
                    // Handled after all concepts are created (needs the ids).
                    continue;
                }
                $isLabel = isset($labelTerms[$term]);
                $isDoc = isset($docTerms[$term]);
                $isNotation = isset($notationTerms[$term]);
                $isMapping = isset($mappingTerms[$term]);
                $keep = $isLabel
                    || ($isDoc && $withDoc)
                    || ($isNotation && $withNotation)
                    || ($isMapping && $withMappings)
                    || (!$isLabel && !$isDoc && !$isNotation && !$isMapping && $withOther);
                if (!$keep) {
                    continue;
                }
                $propertyUri = $vals[0]['property_uri'] ?? '';
                $pid = $uriToPid[$propertyUri] ?? $this->easyMeta->propertyId($term);
                if (!$pid) {
                    $missingProps[$term] = true;
                    continue;
                }
                $lits = [];
                foreach ($vals as $v) {
                    if (($v['type'] ?? '') === 'literal') {
                        $lits[] = ['value' => $v['value'], 'lang' => $v['lang'] ?? ''];
                    } elseif (($v['type'] ?? '') === 'resource' && !empty($v['uri'])) {
                        $data[$term][] = ['type' => 'uri', 'property_id' => $pid, '@id' => $v['uri']];
                    }
                }
                foreach ($literals($lits, $pid) as $value) {
                    $data[$term][] = $value;
                }
            }

            // Hierarchy via level.
            $parentLevel = $level ? $level - 1 : false;
            if ($level && isset($levels[$parentLevel])) {
                $data['skos:broader'] = [[
                    'type' => 'resource:concept',
                    'property_id' => $this->easyMeta->propertyId('skos:broader'),
                    'value_resource_id' => $levels[$parentLevel],
                ]];
            } else {
                $levels = [];
                $level = 0;
                $data['skos:topConceptOf'] = [[
                    'type' => 'resource:item',
                    'property_id' => $this->easyMeta->propertyId('skos:topConceptOf'),
                    'value_resource_id' => $schemeId,
                ]];
            }

            $concept = $this->api->create('concepts', $data)->getContent();
            $conceptId = $concept->id();
            if (!empty($element['uri'])) {
                $uriToId[$element['uri']] = $conceptId;
            }
            $levels[$level] = $conceptId;
            if ($level === 0) {
                $topIds[] = $conceptId;
            } else {
                $narrowers[$levels[$parentLevel]][] = $conceptId;
            }

            if ($withRelations) {
                foreach (($element['values'] ?? []) as $val) {
                    if ($val['term'] === 'skos:related' && ($val['type'] ?? '') === 'resource' && !empty($val['uri'])) {
                        $relatedByConcept[$conceptId][] = $val['uri'];
                    }
                }
            }

            ++$processed;
        }

        // Second pass: internal relations (skos:related) as linked resources.
        if ($relatedByConcept) {
            $relatedPid = $this->easyMeta->propertyId('skos:related');
            foreach ($relatedByConcept as $conceptId => $uris) {
                $append = [];
                foreach ($uris as $uri) {
                    if (isset($uriToId[$uri])) {
                        $append[] = ['type' => 'resource:concept', 'property_id' => $relatedPid, 'value_resource_id' => $uriToId[$uri]];
                    }
                }
                if ($append) {
                    $this->api->update('concepts', $conceptId, ['skos:related' => $append], [], ['isPartial' => true, 'collectionAction' => 'append']);
                }
            }
        }

        if ($missingProps) {
            $this->logger->warn(
                'Some properties of other vocabularies are not present in Omeka and were skipped: {list}.', // @translate
                ['list' => implode(', ', array_keys($missingProps))]
            );
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
            $structure = trim((string) mb_decode_numericentity($structure, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8'));
            $descriptor = trim((string) mb_decode_numericentity($descriptor, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8'));
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
                        'type' => 'resource:concept',
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

            $concept = $this->api->create('concepts', $data)->getContent();
            $conceptId = $concept->id();

            $levels[$level] = $conceptId;
            $ascendance[$level] = $descriptor;
            $ascendance = array_slice($ascendance, 0, $level + 1);

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
     * Transform a string into a slug usable as an identifier in a clean url.
     *
     * Accented characters are transliterated, then any character that is not an
     * ascii alphanumeric, "_" or "-" is replaced by a "_".
     *
     * @see \AdvancedSearch\Controller\Admin\SearchConfigController::slugify()
     * @see \Omeka\Api\Adapter\SiteSlugTrait::slugify()
     */
    protected function slugify(string $input): string
    {
        if (extension_loaded('intl')) {
            static $transliterator;
            $transliterator ??= \Transliterator::createFromRules(':: NFD; :: [:Nonspacing Mark:] Remove; :: NFC;');
            $slug = (string) $transliterator->transliterate($input);
        } elseif (extension_loaded('iconv')) {
            $slug = (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $input);
        } else {
            $slug = $input;
        }
        // Don't lowercase: the clean url pattern accepts the upper case.
        $slug = preg_replace('/[^a-zA-Z0-9_-]+/u', '_', $slug);
        $slug = preg_replace('/_{2,}/', '_', $slug);
        return trim((string) $slug, '_');
    }

    /**
     * Trim and clean string according to options.
     *
     * @todo Factorize the function trimAndCleanString() of ThesaurusController and CreateThesaurus;
     */
    protected function trimAndCleanString($string, array $params): string
    {
        $string = trim((string) $string);
        // Normalize to Unicode NFC so identical-looking strings are identical
        // byte-wise (avoids duplicates, failed lookups and broken truncation
        // with decomposed input, typically from macOS or some SKOS/CSV
        // exports).
        $normalized = \Normalizer::normalize($string, \Normalizer::FORM_C);
        if ($normalized !== false) {
            $string = $normalized;
        }
        if (in_array('trim_punctuation', $params)) {
            $string = trim($string, self::TRIM_PUNCTUATION);
        }
        if (in_array('apostrophe', $params)) {
            $string = strtr($string, ["'" => '’']);
        }
        if (in_array('single_quote', $params)) {
            $string = strtr($string, ['’' => "'"]);
        }
        if (in_array('lowercase', $params)) {
            $string = mb_strtolower($string);
        }
        if (in_array('ucfirst', $params)) {
            $string = mb_strtoupper(mb_substr($string, 0, 1)) . mb_strtolower(mb_substr($string, 1));
        }
        if (in_array('ucwords', $params)) {
            $string = mb_convert_case($string, MB_CASE_TITLE, 'UTF-8');
        }
        if (in_array('uppercase', $params)) {
            $string = mb_strtoupper($string);
        }
        return $string;
    }
}
