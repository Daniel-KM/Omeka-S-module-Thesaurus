<?php

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
     * @var \Zend\Log\Logger
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

    public function perform()
    {
        /**
         * @var \Doctrine\ORM\EntityManager $em
         */
        $services = $this->getServiceLocator();

        // The reference id is the job id for now.
        $referenceIdProcessor = new \Zend\Log\Processor\ReferenceId();
        $referenceIdProcessor->setReferenceId('thesaurus/indexing/job_' . $this->job->getId());

        $this->logger = $services->get('Omeka\Logger');
        $this->logger->addProcessor($referenceIdProcessor);

        $this->entityManager = $services->get('Omeka\EntityManager');
        $this->itemRepository = $this->entityManager->getRepository(\Omeka\Entity\Item::class);
        $this->termRepository = $this->entityManager->getRepository(\Thesaurus\Entity\Term::class);

        $this->api = $services->get('Omeka\ApiManager');
        $this->thesaurus = $services->get('ControllerPluginManager')->get('thesaurus');

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
        $thesaurus = $this->thesaurus;
        $thesaurus($scheme);
        $flatTree = $thesaurus->flatTree();
        if (empty($flatTree)) {
            $message = new Message(
                'Thesaurus #%d has no terms.', // @translate
                $scheme->id()
            );
            $this->logger->err($message);
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
                    $this->logger->error($message);
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
    protected function resetThesaurus(ItemRepresentation $scheme)
    {
        // @see \Omeka\Job\BatchDelete
        $dql = $this->entityManager->createQuery(
            'DELETE FROM Thesaurus\Entity\Term term WHERE term.scheme = ' . $scheme->id()
       );
        $dql->execute();
    }
}
