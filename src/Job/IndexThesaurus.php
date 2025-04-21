<?php declare(strict_types=1);

namespace Thesaurus\Job;

use Common\Stdlib\PsrMessage;
use Doctrine\ORM\EntityManager;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Job\AbstractJob;

class IndexThesaurus extends AbstractJob
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

    public function perform(): void
    {
        $services = $this->getServiceLocator();

        // The reference id is the job id for now.
        $referenceIdProcessor = new \Laminas\Log\Processor\ReferenceId();
        $referenceIdProcessor->setReferenceId('thesaurus/indexing/job_' . $this->job->getId());

        $this->logger = $services->get('Omeka\Logger');
        $this->logger->addProcessor($referenceIdProcessor);

        // Because this is an indexer that is used in background, another entity
        // manager is used to avoid conflicts with the main entity manager, for
        // example when the job is run in foreground or multiple resources are
        // imported in bulk, so a flush() or a clear() will not be applied on
        // the imported resources but only on the indexed resources.
        $this->entityManager = $this->getNewEntityManager($services->get('Omeka\EntityManager'));

        $this->api = $services->get('Omeka\ApiManager');

        /** @var \Omeka\Settings\Settings $settings */
        $settings = $services->get('Omeka\Settings');

        $schemeIds = $this->getArg('schemes') ?: [];
        $schemeId = (int) $this->getArg('scheme');
        if ($schemeId) {
            $schemeIds[] = $schemeId;
        } else {
            $schemeClassId = (int) $settings->get('thesaurus_skos_concept_class_id');
            $schemeTemplateId = (int) $settings->get('thesaurus_skos_scheme_template_id');
            $schemeIds = $schemeTemplateId
                ? $this->api->search('items', ['resource_template_id' => $schemeTemplateId], ['returnScalar' => 'id'])->getContent()
                : ($schemeClassId ? $this->api->search('items', ['resource_class_id' => $schemeClassId], ['returnScalar' => 'id'])->getContent() : []);
            if (!count($schemeIds)) {
                $this->logger->err(
                    'No thesaurus in the database.' // @translate
                );
                return;
            }
        }

        foreach ($schemeIds as $schemeId) {
            $this->indexScheme((int) $schemeId);
        }
    }

    /**
     * Index a thesaurus: get the tree, update terms positions, and save them.
     */
    protected function indexScheme(int $schemeId): bool
    {
        try {
            $scheme = $this->api->read('items', ['id' => $schemeId])->getContent();
        } catch (\Omeka\Api\Exception\NotFoundException $e) {
            $this->logger->err(
                'Thesaurus #{item_id} not found.', // @translate
                ['item_id' => $schemeId]
            );
            return false;
        }

        $this->logger->notice(
            'Starting indexing of thesaurus "{title}" (#{item_id}).', // @translate
            ['title' => $scheme->displayTitle(), 'item_id' => $schemeId]
        );

        // Reset the scheme first.
        // Ids are not kept, since they are only an index currently.
        $this->resetThesaurus($scheme);

        // Get the tree from the values.
        $flatTree = $this->flatTree($scheme);
        if (empty($flatTree)) {
            $this->logger->err(
                'Thesaurus "{title}" (#{item_id}) not indexed.', // @translate
                ['title' => $scheme->displayTitle(), 'item_id' => $schemeId]
            );
            return false;
        }

        // Save all terms in the right order (so position is useless currently).
        $schemeResource = $this->entityManager->find(\Omeka\Entity\Item::class, $scheme->id());
        $root = null;
        $broader = null;
        $position = 0;
        $ancestors = [];
        $level = 0;
        $previousLevel = 0;
        foreach (array_chunk($flatTree, self::BATCH_SIZE) as $chunk) {
            foreach ($chunk as $concept) {
                $item = $this->entityManager->find(\Omeka\Entity\Item::class, $concept['self']->id());
                $level = $concept['level'];

                $term = new \Thesaurus\Entity\Term;
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
                    $this->logger->err(
                        'Thesaurus #{item_id} has a missing root for item #{item_id_2}.', // @translate
                        ['item_id' => $scheme->id(), 'item_id_2' => $concept['self']->id()]
                    );
                    $this->resetThesaurus($scheme);
                    return false;
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

        $this->logger->notice(
            'Thesaurus "{title}" (#{item_id}) indexed (full thesaurus: #{thesaurus_id}).', // @translate
            ['title' => $scheme->displayTitle(), 'item_id' => $schemeId, 'thesaurus_id' => $schemeId]
        );

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
     */
    protected function resourcesFromValue(ItemRepresentation $item, string $term): array
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
     */
    protected function flatTree(ItemRepresentation $scheme): array
    {
        $tops = $this->resourcesFromValue($scheme, 'skos:hasTopConcept');
        if (empty($tops)) {
            $this->logger->err(
                'Thesaurus #{item_id} has no top concepts.', // @translate
                ['item_id' => $scheme->id()]
            );
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
     * @param int $level Internal level.
     */
    protected function recursiveFlatBranch(ItemRepresentation $item, array $branch = [], int $level = 0): array
    {
        if ($level > $this->maxAncestors) {
            throw new \Omeka\Api\Exception\BadResponseException(new PsrMessage(
                'The term #{item_id} has more than {count} levels.', // @translate
                ['item_id' => $item->id(), 'count' => $this->maxAncestors]
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

    /**
     * Create a new EntityManager with the same config.
     */
    private function getNewEntityManager(EntityManager $entityManager): EntityManager
    {
        return EntityManager::create(
            $entityManager->getConnection(),
            $entityManager->getConfiguration(),
            $entityManager->getEventManager()
        );
    }
}
