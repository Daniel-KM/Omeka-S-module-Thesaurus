<?php declare(strict_types=1);

namespace Thesaurus\Job;

use Omeka\Job\AbstractJob;
use Omeka\Stdlib\Message;

class CreateThesaurus extends AbstractJob
{
    /**
     * @var \Laminas\Log\Logger
     */
    protected $logger;

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

        $name = $this->getArg('name');
        if (!$name) {
            $this->logger->err('A name is required to create a thesaurus.'); // @translate
        }
        $input = $this->getArg('input');
        if (!$input) {
            $this->logger->err('A list of concepts is required to create a thesaurus.'); // @translate
        }
        if (!$name || !$input) {
            return;
        }

        $this->api = $services->get('Omeka\ApiManager');

        // Prepare resource classes and templates.
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

        // First create the item set.
        $data = [
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

        $baseConcept = [
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

        $levels = [];
        $topIds = [];
        $narrowers = [];

        foreach ($input as $line) {
            $descriptor = trim($line);
            $level = strrpos($line, "\t");
            $level = $level === false ? 0 : ++$level;
            $parentLevel = $level ? $level - 1 : false;
            $data = $baseConcept + [
                'skos:prefLabel' => [
                    [
                        'type' => 'literal',
                        'property_id' => $skosIds['skos:prefLabel'],
                        '@value' => $descriptor,
                    ],
                ],
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

            if ($level === 0) {
                $topIds[] = $conceptId;
            } else {
                $narrowers[$levels[$parentLevel]][] = $conceptId;
            }
        }

        // Fourth, append narrower concepts to concepts.
        foreach ($narrowers as $parentId => $narrowerIds) {
            if (!$narrowerIds) {
                continue;
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

        $message = new Message(
            'The thesaurus "%1$s" is ready, with %2$d concepts.', // @translate
            ucfirst($name), count($input)
        );
        $this->logger->notice($message);
    }
}
