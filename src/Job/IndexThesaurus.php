<?php declare(strict_types=1);

namespace Thesaurus\Job;

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
     * Allow another job to index specific thesaurus without using job args.
     *
     * @var int[]|null
     */
    protected $schemeIdsToIndex;

    /**
     * Set the thesaurus to index, bypassing the job args and the detection via
     * the resource template or class of the schemes.
     *
     * @param int[] $schemeIds
     */
    public function setSchemeIds(array $schemeIds): self
    {
        $this->schemeIdsToIndex = array_map('intval', $schemeIds);
        return $this;
    }

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

        if ($this->schemeIdsToIndex !== null) {
            $schemeIds = $this->schemeIdsToIndex;
        } else {
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
            }
        }

        if (!count($schemeIds)) {
            $this->logger->err(
                'No thesaurus in the database.' // @translate
            );
            return;
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

        // Update the tree of all concepts in the right order.
        // The concepts are resources that already exist: only their structure
        // is set here (the top concept, the broader concept and the position).
        $schemeResource = $this->entityManager->find(\Omeka\Entity\Item::class, $scheme->id());
        $top = null;
        $broader = null;
        $position = 0;
        $totalIndexed = 0;
        $ancestors = [];
        $level = 0;
        $previousLevel = 0;
        foreach (array_chunk($flatTree, self::BATCH_SIZE) as $chunk) {
            foreach ($chunk as $concept) {
                /** @var \Thesaurus\Entity\Concept $conceptEntity */
                $conceptEntity = $this->entityManager->find(\Thesaurus\Entity\Concept::class, $concept['id']);
                if (!$conceptEntity) {
                    continue;
                }
                ++$totalIndexed;
                $level = $concept['level'];

                $conceptEntity
                    ->setScheme($schemeResource)
                    ->setPosition(++$position);

                $isTop = $concept['level'] === 0;
                if ($isTop) {
                    $top = $conceptEntity;
                    $broader = null;
                    $ancestors = [];
                } elseif (!$top) {
                    $this->logger->err(
                        'Thesaurus #{item_id} has a missing top concept for item #{item_id_2}.', // @translate
                        ['item_id' => $scheme->id(), 'item_id_2' => $concept['id']]
                    );
                    $this->resetThesaurus($scheme);
                    return false;
                } else {
                    if ($level < $previousLevel) {
                        $ancestors = array_slice($ancestors, 0, $level + 1);
                    }
                    $broader = $ancestors[$level - 1];
                }

                $conceptEntity
                    ->setTop($top)
                    ->setBroader($broader);
                $this->entityManager->persist($conceptEntity);
                $ancestors[$level] = $conceptEntity;
                $previousLevel = $level;
            }
            $this->entityManager->flush();
        }
        // It's recursive, so clear can be done only when all terms are created.
        // TODO Clear entity manager and reload ancestors at each chunk.
        $this->entityManager->clear();

        /*
        // Fill top concepts. Not needed if there is no clear during chunk.
        $concepts = $this->conceptRepository->findBy(['scheme' => $scheme->id(), 'top' => null, 'broader' => null]);
        foreach ($concepts as $concept) {
            $concept->setTop($concept);
            $this->entityManager->persist($concept);
        }
        $this->entityManager->flush();
        $this->entityManager->clear();
        */

        // A thesaurus that is not converted yet is made of items, that have no
        // structure to index: the structure is read from the skos values.
        if (!$totalIndexed) {
            $this->logger->notice(
                'Thesaurus "{title}" (#{item_id}) is made of items: there is no structure to index. Run the task "Convert items into concepts" to index it.', // @translate
                ['title' => $scheme->displayTitle(), 'item_id' => $schemeId]
            );
            return true;
        }

        $this->logger->notice(
            'Thesaurus "{title}" (#{item_id}) indexed: {count} concepts.', // @translate
            ['title' => $scheme->displayTitle(), 'item_id' => $schemeId, 'count' => $totalIndexed]
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
        // The concepts are resources, so they are not deleted: only their tree
        // structure (top, broader, position) is reset before the reindexation.
        $this->entityManager->createQuery(
            'UPDATE Thesaurus\Entity\Concept concept'
            . ' SET concept.top = NULL, concept.broader = NULL, concept.position = NULL'
            . ' WHERE concept.scheme = ' . $scheme->id()
        )->execute();
    }

    /**
     * Create an ordered flat thesaurus for a scheme.
     *
     * The tree is built from the skos values, in both directions, with a few
     * grouped queries, so a relation set in one direction only is indexed too.
     *
     * @return array List of ["id" => concept id, "level" => level].
     */
    protected function flatTree(ItemRepresentation $scheme): array
    {
        /** @var \Thesaurus\Stdlib\Thesaurus $thesaurus */
        $thesaurus = $this->getServiceLocator()->build('Thesaurus\Thesaurus');
        $result = [];
        foreach ($thesaurus($scheme)->flatTree() as $id => $data) {
            $result[] = ['id' => (int) $id, 'level' => (int) $data['level']];
        }
        return $result;
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
