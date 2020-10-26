<?php declare(strict_types=1);

namespace Thesaurus\Job;

use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Job\AbstractJob;
use Omeka\Stdlib\Message;
use Thesaurus\Entity\Term;

class Indexing extends AbstractJob
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
    protected $termRepository;

    public function perform(): void
    {
        /**
         * @var \Doctrine\ORM\EntityManager $em
         */
        $services = $this->getServiceLocator();

        // The reference id is the job id for now.
        $referenceIdProcessor = new \Laminas\Log\Processor\ReferenceId();
        $referenceIdProcessor->setReferenceId('thesaurus/indexing/job_' . $this->job->getId());

        $this->logger = $services->get('Omeka\Logger');
        $this->logger->addProcessor($referenceIdProcessor);

        $this->entityManager = $services->get('Omeka\EntityManager');
        $this->itemRepository = $this->entityManager->getRepository(\Omeka\Entity\Item::class);
        $this->termRepository = $this->entityManager->getRepository(\Thesaurus\Entity\Term::class);

        $this->api = $services->get('Omeka\ApiManager');

        $conceptSchemeId = $this->api->search('resource_classes', ['term' => 'skos:ConceptScheme'])->getContent()[0]->id();
        $response = $this->api->search('items', ['resource_class_id' => $conceptSchemeId]);
        $totalSchemes = $response->getTotalResults();
        if (!$totalSchemes) {
            $this->logger->warn(
                'No thesaurus to index.' // @translate
            );
            return;
        }

        $message = new Message(
            'Starting indexing of %d thesaurus.', // @translate
            $totalSchemes
        );
        $this->logger->notice($message);

        foreach ($response->getContent() as $key => $scheme) {
            if ($this->shouldStop()) {
                $message = new Message(
                    'Indexation was stopped. %d/%d thesaurus processed.', // @translate
                    $key,
                    count($totalSchemes)
                );
                $this->logger->notice($message);
                return;
            }
            $result = $this->indexScheme($scheme);
            if ($result) {
                $message = new Message(
                    'Thesaurus #%d indexed.', // @translate
                    $scheme->id()
                );
                $this->logger->notice($message);
            } else {
                $message = new Message(
                    'Thesaurus #%d not indexed.', // @translate
                    $scheme->id()
                );
                $this->logger->err($message);
            }
        }

        $message = new Message(
            'Ended indexing of %d thesaurus.', // @translate
            $totalSchemes
        );
        $this->logger->notice($message);
    }

    /**
     * Index a thesaurus: get the tree, update terms positions, and save them.
     *
     * @param ItemRepresentation $scheme
     * @return bool
     */
    protected function indexScheme(ItemRepresentation $scheme)
    {
        // Reset the scheme first.
        // Ids are not kept, since they are only an index currently.
        $this->resetThesaurus($scheme);

        // Get the tree from the values.
        $flatTree = $this->flatTree($scheme);
        if (empty($flatTree)) {
            return false;
        }

        // Save all terms in the right order (so position is useless currently).
        $schemeResource = $this->itemRepository->find($scheme->id());
        $root = null;
        $broader = null;
        $position = 0;
        $ancestors = [];
        $level = 0;
        $previousLevel = 0;
        foreach (array_chunk($flatTree, self::BATCH_SIZE) as $chunk) {
            foreach ($chunk as $concept) {
                $item = $this->itemRepository->find($concept['self']->id());
                $level = $concept['level'];

                $term = new Term;
                $term
                    ->setItem($item)
                    ->setScheme($schemeResource)
                    ->setPosition(++$position);

                $isRoot = $concept['level'] === 0;
                if ($isRoot) {
                    $root = $term;
                    $broader = null;
                    $ancestors = [];
                } elseif (!$root) {
                    $message = new Message(
                        'Thesaurus #%1$d has a missing root for item #%2$d.', // @translate
                        $scheme->id(),
                        $concept['self']->id()
                    );
                    $this->logger->err($message);
                    $this->resetThesaurus($scheme);
                    return;
                } else {
                    if ($level < $previousLevel) {
                        $ancestors = array_slice($ancestors, 0, $level + 1);
                    }
                    $broader = $ancestors[$level - 1];
                }

                $term
                    ->setRoot($root)
                    ->setBroader($broader);
                $this->entityManager->persist($term);
                $ancestors[$level] = $term;
                $previousLevel = $level;
            }
            $this->entityManager->flush();
        }
        // It's a recursive, so a clear can be done only when all terms are
        // created.
        // TODO Clear entity manager and reload ancestors at each chunk.
        $this->entityManager->clear();

        /*
        // Fill root terms. Not needed if there is no clear during chunk.
        $terms = $this->termRepository->findBy(['scheme' => $scheme->id(), 'root' => null, 'broader' => null]);
        foreach ($terms as $term) {
            $term->setRoot($term);
            $this->entityManager->persist($term);
        }
        $this->entityManager->flush();
        $this->entityManager->clear();
        */

        return true;
    }

    /**
     * Reset a thesaurus.
     *
     * @param ItemRepresentation $scheme
     */
    protected function resetThesaurus(ItemRepresentation $scheme): void
    {
        // @see \Omeka\Job\BatchDelete
        $dql = $this->entityManager->createQuery(
            'DELETE FROM Thesaurus\Entity\Term term WHERE term.scheme = ' . $scheme->id()
        );
        $dql->execute();
    }

    /**
     * Get all linked resources of this item for a term.
     *
     * @param ItemRepresentation $item
     * @param string $term
     * @return ItemRepresentation[]
     */
    protected function resourcesFromValue(ItemRepresentation $item, $term)
    {
        $result = [];
        $values = $item->values();
        if (isset($values[$term])) {
            /** @var \Omeka\Api\Representation\ValueRepresentation $value */
            foreach ($values[$term]['values'] as $value) {
                if (in_array($value->type(), ['resource', 'resource:item'])) {
                    // Manage private resources.
                    if ($resource = $value->valueResource()) {
                        // Manage duplicates.
                        $result[$resource->id()] = $resource;
                    }
                }
            }
        }
        return array_values($result);
    }

    /**
     * Create an ordered flat thesaurus for a scheme.
     *
     * @param ItemRepresentation $scheme
     * @return array
     */
    protected function flatTree(ItemRepresentation $scheme)
    {
        $tops = $this->resourcesFromValue($scheme, 'skos:hasTopConcept');
        if (empty($tops)) {
            $message = new Message(
                'Thesaurus #%d has no top concepts.', // @translate
                $scheme->id()
            );
            $this->logger->err($message);
            return [];
        }

        $result = [];
        foreach ($tops as $item) {
            $result[$item->id()] = [
                'self' => $item,
                'level' => 0,
            ];
            $result = $this->recursiveFlatBranch($item, $result, 1);
        }

        return $result;
    }

    /**
     * Recursive method to get the flat descendant tree of an item.
     *
     * @param ItemRepresentation $item
     * @param array $branch Internal param for recursive process.
     * @param int $level
     * @return array|false
     */
    protected function recursiveFlatBranch(ItemRepresentation $item, array $branch = [], $level = 0)
    {
        if ($level > $this->maxAncestors) {
            throw new \Omeka\Api\Exception\BadResponseException(sprintf(
                'The term #%1$d has more than %2$d levels.', // @translate
                $item->id(),
                $this->maxAncestors
            ));
        }

        $children = $this->resourcesFromValue($item, 'skos:narrower');
        foreach ($children as $child) {
            $id = $child->id();
            if (!isset($branch[$id])) {
                $branch[$id] = [
                    'self' => $child,
                    'level' => $level,
                ];
                $branch = $this->recursiveFlatBranch($child, $branch, $level + 1);
            }
        }

        return $branch;
    }
}
