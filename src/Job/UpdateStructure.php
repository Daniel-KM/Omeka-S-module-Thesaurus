<?php declare(strict_types=1);

namespace Thesaurus\Job;

use Omeka\Job\AbstractJob;
use Omeka\Stdlib\Message;
use Thesaurus\Mvc\Controller\Plugin\Thesaurus;

class UpdateStructure extends AbstractJob
{
    /**
     * Limit for the loop to avoid heavy sql requests.
     *
     * @var int
     */
    const BATCH_SIZE = 100;

    /**
     * Maximum ancestor to avoid infinite loops.
     *
     * @var int
     */
    protected $maxAncestors = 100;

    /**
     * @var \Laminas\Log\Logger
     */
    protected $logger;

    /**
     * @var \Omeka\Api\Manager
     */
    protected $api;

    /**
     * @var \Doctrine\ORM\EntityManager
     */
    protected $entityManager;

    /**
     * @var \Doctrine\ORM\EntityRepository
     */
    protected $itemRepository;

    /**
     * @var \Doctrine\ORM\EntityRepository
     */
    protected $vocabularyRepository;

    /**
     * @var \Doctrine\ORM\EntityRepository
     */
    protected $propertyRepository;

    /**
     * @var array
     */
    protected $flatTree;

    public function perform(): void
    {
        $services = $this->getServiceLocator();

        // The reference id is the job id for now.
        $referenceIdProcessor = new \Laminas\Log\Processor\ReferenceId();
        $referenceIdProcessor->setReferenceId('thesaurus/structure/job_' . $this->job->getId());

        $this->logger = $services->get('Omeka\Logger');
        $this->logger->addProcessor($referenceIdProcessor);

        $this->entityManager = $services->get('Omeka\EntityManager');
        $this->itemRepository = $this->entityManager->getRepository(\Omeka\Entity\Item::class);
        $this->vocabularyRepository = $this->entityManager->getRepository(\Omeka\Entity\Vocabulary::class);
        $this->propertyRepository = $this->entityManager->getRepository(\Omeka\Entity\Property::class);

        $this->api = $services->get('Omeka\ApiManager');

        $schemeId = (int) $this->getArg('scheme');
        if (!$schemeId) {
            $this->logger->err(new Message(
                'No thesaurus specified.' // @translate
            ));
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

        $scheme = $thesaurus->scheme();

        // Keep scheme data to avoid issue with doctrine after restructuration.
        $schemeId = $scheme->id();
        $schemeTitle = $scheme->displayTitle();

        // This is a flat tree.
        $structure = $this->getArg('structure') ?: [];
        if (empty($structure)) {
            $this->logger->warn(new Message(
                'The thesaurus "%1$s" (#%1$d) is empty. No process can be done for now. Include concepts in thesaurus first.', // @translate
                $schemeId
            ));
            return;
        }

        $message = new Message(
            'Starting restructuration of thesaurus "%1$s" (#%2$d).', // @translate
            $schemeTitle, $schemeId
        );
        $this->logger->notice($message);

        $result = $this->restructureThesaurus($thesaurus, $structure);

        if ($result) {
            // Args are same (just need scheme).
            // Do not dispatch to avoid issue with doctrine: authenticated owner
            // should be refreshed.
            $indexing = new \Thesaurus\Job\Indexing($this->job, $services);
            $indexing->perform();
            $message = new Message(
                'Concepts were restructured and reindexed for thesaurus "%1$s" (#%2$d).', // @translate
                $schemeTitle, $schemeId
            );
            $this->logger->notice($message);
        } else {
            $message = new Message(
                'An error occurred. Ended restructuration of thesaurus "%1$s" (#%2$d).', // @translate
                $schemeTitle, $schemeId
            );
            $this->logger->warn($message);
        }
    }

    /**
     * Restructure a thesaurus.
     *
     * The removed concepts are not removed from Omeka, but only from this
     * thesaurus (properties inScheme and topConceptOf).
     * Relations of removed concepts are not updated, even if they were moved.
     * @todo Update relations of removed concepts.
     *
     * Orm is quicker to insert, but not to update or to delete.
     * @see https://www.doctrine-project.org/projects/doctrine-orm/en/2.9/reference/batch-processing.html
     *
     * @param array $structure Ordered structure (flat tree) with key as item id and array
     *   as value with keys "parent" and "remove".
     */
    protected function restructureThesaurus(Thesaurus $thesaurus, array $structure): bool
    {
        // The parent and the children are stored at the same time, so prepare
        // them one time, and identify all removed elements.
        $tree = $structure;
        $removed = [];
        $topConcepts = [];
        foreach ($tree as $id => &$data) {
            if (!(int) $id || !array_key_exists('parent', $data)) {
                $message = new Message(
                    'Missing id or parent key for id #%d.', // @translate
                    $id
                );
                $this->logger->err($message);
                return false;
            }
            $parent = $data['parent'] = (int) $data['parent'] ?: null;
            // Because the structure is ordered, all sub-concepts are removed
            // by checking its direct parent only.
            if (!empty($data['remove'])) {
                $removed[$id] = $id;
            }
            if ($parent && !empty($tree[$parent]['remove'])) {
                $removed[$id] = $id;
                $data['remove'] = true;
            }
            if ($parent && empty($data['remove'])) {
                isset($tree[$parent]['children'])
                    ? $tree[$parent]['children'][] = $id
                    : $tree[$parent]['children'] = [$id];
            }
            if (!$parent && empty($data['remove'])) {
                $topConcepts[$id] = $id;
            }
        }
        unset($data);

        // Store the current flat tree for comparaison.
        $this->flatTree = $thesaurus->flatTree();

        // First, remove relations for all removed concepts.
        $result = $this->removeConceptsFromThesaurus($thesaurus, $removed);
        if (!$result) {
            return false;
        }

        // Second, update top concepts in scheme.
        $result = $this->updateTopConceptsForThesaurus($thesaurus, $topConcepts);
        if (!$result) {
            return false;
        }

        // Third, update each remaining concepts.
        $tree = array_diff_key($tree, $removed);
        if (!$tree) {
            $message = new Message(
                'The thesaurus is now empty.', // @translate
                $id
            );
            $this->logger->warn($message);
            return true;
        }

        $result = $this->updateRemainingConceptsForThesaurus($thesaurus, $tree);
        if (!$result) {
            return false;
        }

        return true;
    }

    protected function removeConceptsFromThesaurus(Thesaurus $thesaurus, array $removed): bool
    {
        if (!$removed) {
            return true;
        }

        $vocabulary = $this->vocabularyRepository->findOneBy(['namespaceUri' => 'http://www.w3.org/2004/02/skos/core#']);
        $inScheme = $this->propertyRepository->findOneBy(['vocabulary' => $vocabulary, 'localName' => 'inScheme']);
        $topConceptOf = $this->propertyRepository->findOneBy(['vocabulary' => $vocabulary, 'localName' => 'topConceptOf']);

        $dql = <<<'DQL'
DELETE FROM Omeka\Entity\Value value
WHERE value.resource IN (:resources)
    AND value.valueResource = :scheme
    AND value.property IN (:properties)
DQL;
        $params = [
            'resources' => $removed,
            'scheme' => $thesaurus->scheme()->id(),
            'properties' => [
                $inScheme->getId(),
                $topConceptOf->getId(),
            ],
        ];
        $dql = $this->entityManager->createQuery($dql);
        $dql->execute($params);

        return true;
    }

    protected function updateTopConceptsForThesaurus(Thesaurus $thesaurus, array $topConcepts): bool
    {
        // Don't use $thesaurus->tops(), it's not up to date.
        $existings = [];
        foreach ($this->flatTree as $id => $element) {
            if (empty($element['self']['parent'])) {
                $existings[$id] = $id;
            }
        }

        if ($existings === $topConcepts) {
            return true;
        }

        // TODO Use getReference() when possible and no repository.
        $schemeId = $thesaurus->scheme()->id();
        $scheme = $this->itemRepository->find($schemeId);
        $vocabulary = $this->vocabularyRepository->findOneBy(['namespaceUri' => 'http://www.w3.org/2004/02/skos/core#']);
        $hasTopConcept = $this->propertyRepository->findOneBy(['vocabulary' => $vocabulary, 'localName' => 'hasTopConcept']);

        // Order is taken into account, so all existing top concepts are removed
        // then all new top concepts are inserted, even if they are the same.
        // ValueHydrator updates existing then remove or insert remaining to
        // reuse existing ids and avoid big ids, but value ids are useless.

        $dql = <<<'DQL'
DELETE FROM Omeka\Entity\Value value
WHERE value.resource = :scheme
    AND value.property = :has_top_concept
    AND value.valueResource IN (:removeds)
DQL;
        $params = [
            'scheme' => $schemeId,
            'has_top_concept' => $hasTopConcept->getId(),
            'removeds' => array_values($existings),
        ];
        $dql = $this->entityManager->createQuery($dql);
        $this->entityManager->flush();
        $dql->execute($params);

        foreach ($topConcepts as $topConceptId) {
            $topConcept = $this->itemRepository->find($topConceptId);
            if (!$topConcept) {
                continue;
            }
            // Omeka entities are not fluid.
            $value = new \Omeka\Entity\Value;
            $value->setResource($scheme);
            $value->setProperty($hasTopConcept);
            $value->setValueResource($topConcept);
            $value->setType('resource:item');
            $this->entityManager->persist($value);
        }
        $this->entityManager->flush();

        return true;
    }

    /**
     * @param array $structure Structure without removed concepts.
     */
    protected function updateRemainingConceptsForThesaurus(Thesaurus $thesaurus, array $structure): bool
    {
        if (!$structure) {
            return true;
        }

        // TODO Use getReference() and no repository.
        $schemeId = $thesaurus->scheme()->id();
        $scheme = $this->itemRepository->find($schemeId);
        $vocabulary = $this->vocabularyRepository->findOneBy(['namespaceUri' => 'http://www.w3.org/2004/02/skos/core#']);
        $topConceptOf = $this->propertyRepository->findOneBy(['vocabulary' => $vocabulary, 'localName' => 'topConceptOf']);
        $broader = $this->propertyRepository->findOneBy(['vocabulary' => $vocabulary, 'localName' => 'broader']);
        $narrower = $this->propertyRepository->findOneBy(['vocabulary' => $vocabulary, 'localName' => 'narrower']);

        // No stop in order to keep coherence.
        foreach (array_chunk($structure, self::BATCH_SIZE, true) as $chunk) {
            foreach ($chunk as $conceptId => $data) {
                // Should not occur: already removed and checked in previous steps.
                if (!empty($data['remove'])) {
                    continue;
                }
                $concept = $this->itemRepository->find($conceptId);
                if (!$concept) {
                    continue;
                }

                $existing = $this->flatTree[$conceptId] ?? null;
                $data['children'] = $data['children'] ?? [];

                if (!$existing) {
                    if (empty($data['parent'])) {
                        // Omeka entities are not fluid.
                        $value = new \Omeka\Entity\Value;
                        $value->setResource($concept);
                        $value->setProperty($topConceptOf);
                        $value->setValueResource($scheme);
                        $value->setType('resource:item');
                        $this->entityManager->persist($value);
                    } else {
                        $parent = $this->itemRepository->find($data['parent']);
                        if ($parent) {
                            // Omeka entities are not fluid.
                            $value = new \Omeka\Entity\Value;
                            $value->setResource($concept);
                            $value->setProperty($broader);
                            $value->setValueResource($parent);
                            $value->setType('resource:item');
                            $this->entityManager->persist($value);
                        }
                    }
                    foreach ($data['children'] as $child) {
                        $childConcept = $this->itemRepository->find($data['parent']);
                        if ($childConcept) {
                            // Omeka entities are not fluid.
                            $value = new \Omeka\Entity\Value;
                            $value->setResource($concept);
                            $value->setProperty($narrower);
                            $value->setValueResource($childConcept);
                            $value->setType('resource:item');
                            $this->entityManager->persist($value);
                        }
                    }
                    $this->entityManager->flush();
                    continue;
                }

                // Process only updated concepts (broader or narrowers).

                // Update parent and top (delete then insert).
                if ($existing['self']['parent'] !== $data['parent']) {
                    // Deletion of the two values in the two properties are done
                    // separately to allow multi-thesaurus.
                    $dql = <<<'DQL'
DELETE FROM Omeka\Entity\Value value
WHERE value.resource = :concept
    AND value.property = :top_concept_of
    AND value.valueResource = :scheme
DQL;
                    $params = [
                        'concept' => $conceptId,
                        'top_concept_of' => $topConceptOf->getId(),
                        'scheme' => $schemeId,
                    ];
                    $dql = $this->entityManager->createQuery($dql);
                    $this->entityManager->flush();
                    $dql->execute($params);

                    $dql = <<<'DQL'
DELETE FROM Omeka\Entity\Value value
WHERE value.resource = :concept
    AND value.property = :broader
    AND value.valueResource = :broader_concept
DQL;
                    $params = [
                        'concept' => $conceptId,
                        'broader' => $broader->getId(),
                        'broader_concept' => $existing['self']['parent'],
                    ];
                    $dql = $this->entityManager->createQuery($dql);
                    $dql->execute($params);

                    if (empty($data['parent'])) {
                        // Omeka entities are not fluid.
                        $value = new \Omeka\Entity\Value;
                        $value->setResource($concept);
                        $value->setProperty($topConceptOf);
                        $value->setValueResource($scheme);
                        $value->setType('resource:item');
                        $this->entityManager->persist($value);
                    } else {
                        $parent = $this->itemRepository->find($data['parent']);
                        if ($parent) {
                            // Omeka entities are not fluid.
                            $value = new \Omeka\Entity\Value;
                            $value->setResource($concept);
                            $value->setProperty($broader);
                            $value->setValueResource($parent);
                            $value->setType('resource:item');
                            $this->entityManager->persist($value);
                        }
                    }
                }

                // Update children if needed.
                // Order is taken into account: existing children are deleted,
                // then all children are inserted.
                if (array_values($existing['self']['children']) !== array_values($data['children'])) {
                    if ($existing['self']['children']) {
                        $dql = <<<'DQL'
DELETE FROM Omeka\Entity\Value value
WHERE value.resource = :concept
    AND value.property IN (:narrower)
    AND value.valueResource IN (:narrowers)
DQL;
                        $params = [
                            'concept' => $conceptId,
                            'narrower' => $narrower->getId(),
                            'narrowers' => array_values($existing['self']['children']),
                        ];
                        $dql = $this->entityManager->createQuery($dql);
                        $this->entityManager->flush();
                        $dql->execute($params);
                    }

                    foreach ($data['children'] as $child) {
                        $childConcept = $this->itemRepository->find($child);
                        if ($childConcept) {
                            // Omeka entities are not fluid.
                            $value = new \Omeka\Entity\Value;
                            $value->setResource($concept);
                            $value->setProperty($narrower);
                            $value->setValueResource($childConcept);
                            $value->setType('resource:item');
                            $this->entityManager->persist($value);
                        }
                    }
                }
                $this->entityManager->flush();
            }

            $this->entityManager->clear();

            // Reinit after clear.
            $schemeId = $thesaurus->scheme()->id();
            $scheme = $this->itemRepository->find($schemeId);
            $vocabulary = $this->vocabularyRepository->findOneBy(['namespaceUri' => 'http://www.w3.org/2004/02/skos/core#']);
            $topConceptOf = $this->propertyRepository->findOneBy(['vocabulary' => $vocabulary, 'localName' => 'topConceptOf']);
            $broader = $this->propertyRepository->findOneBy(['vocabulary' => $vocabulary, 'localName' => 'broader']);
            $narrower = $this->propertyRepository->findOneBy(['vocabulary' => $vocabulary, 'localName' => 'narrower']);
        }

        // Is there remaining resources?
        $this->entityManager->flush();

        return true;
    }
}
