<?php declare(strict_types=1);

namespace Thesaurus\Api\Adapter;

use Doctrine\ORM\QueryBuilder;
use Omeka\Api\Adapter\AbstractEntityAdapter;
use Omeka\Api\Request;
use Omeka\Entity\EntityInterface;
use Omeka\Stdlib\ErrorStore;
use Thesaurus\Entity\Term;

class TermAdapter extends AbstractEntityAdapter
{
    protected $maxAncestors = 100;

    protected $sortFields = [
        'id' => 'id',
        'item' => 'item',
        'scheme' => 'scheme',
        'root' => 'root',
        'broader' => 'broader',
        'position' => 'position',
    ];

    protected $scalarFields = [
        'id' => 'id',
        'item' => 'item',
        'scheme' => 'scheme',
        'root' => 'root',
        'broader' => 'broader',
        'position' => 'position',
    ];

    public function getResourceName()
    {
        return 'terms';
    }

    public function getRepresentationClass()
    {
        return \Thesaurus\Api\Representation\TermRepresentation::class;
    }

    public function getEntityClass()
    {
        return \Thesaurus\Entity\Term::class;
    }

    public function buildQuery(QueryBuilder $qb, array $query): void
    {
        $expr = $qb->expr();

        if (isset($query['item'])) {
            $qb->andWhere(
                $expr->eq(
                    'omeka_root.item',
                    $this->createNamedParameter($qb, $query['item'])
                )
            );
        }
        if (isset($query['scheme'])) {
            $qb->andWhere(
                $expr->eq(
                    'omeka_root.scheme',
                    $this->createNamedParameter($qb, $query['scheme'])
                )
            );
        }
        if (isset($query['root'])) {
            $qb->andWhere(
                $expr->eq(
                    'omeka_root.root',
                    $this->createNamedParameter($qb, $query['root'])
                )
            );
        }
        if (isset($query['broader'])) {
            $qb->andWhere(
                $expr->eq(
                    'omeka_root.broader',
                    $this->createNamedParameter($qb, $query['broader'])
                )
            );
        }
    }

    public function validateEntity(EntityInterface $entity, ErrorStore $errorStore): void
    {
        $item = $entity->getItem();
        if (!$item) {
            $errorStore->addError('o:item', 'A term must be an item.'); // @translate
        }
        $scheme = $entity->getScheme();
        if (!$scheme) {
            $errorStore->addError('skos:inScheme', 'A term must have a scheme.'); // @translate
        }
        if ($item && $scheme) {
            $term = $this->getEntityManager()->getRepository(\Thesaurus\Entity\Term::class)
                ->findOneBy([
                    'item' => $item,
                    'scheme' => $scheme,
                ]);
            if ($term && (
                // Cannot create.
                !$entity->getId()
                // Cannot update.
                || $term->getId() !== $entity->getId()
            )) {
                $errorStore->addError('o:item', 'This item is already a term in this scheme.'); // @translate
            }
        }

        $broader = $entity->getBroader();
        if ($broader) {
            if ($scheme->getId() !== $broader->getScheme()->getId()) {
                $errorStore->addError('skos:broader', 'The scheme of the broader term must be the same than term one.'); // @translate
            } else {
                $top = $this->ancestor($entity);
                if (!$top) {
                    $errorStore->addError('skos:broader', 'A term must not be a ancestor of itself.'); // @translate
                }
            }
        }
    }

    public function hydrate(
        Request $request,
        EntityInterface $entity,
        ErrorStore $errorStore
    ): void {
        /* @var \Thesaurus\Entity\Term $entity */
        if ($this->shouldHydrate($request, 'o:item')) {
            $entity->setItem($request->getValue('o:item'));
        }
        if ($this->shouldHydrate($request, 'skos:inScheme')) {
            $entity->setScheme($request->getValue('skos:inScheme'));
        }
        if ($this->shouldHydrate($request, 'skos:broader')) {
            $broader = $request->getValue('skos:broader');
            $entity->setBroader($broader);
        }
        $broader = $entity->getBroader();
        if ($broader) {
            $entity->setRoot($this->ancestor($broader));
        } else {
            $entity->setRoot($entity);
        }
        // The position must be updated via a indexing job.
        if ($this->shouldHydrate($request, 'o:position')) {
            $entity->setPosition($request->getValue('o:position'));
        }
    }

    /**
     * Recursive method to get the top concept of a term.
     *
     * @param Term $term
     * @return \Thesaurus\Entity\Term|false
     */
    protected function ancestor(Term $term, $level = 0)
    {
        if ($level > $this->maxAncestors) {
            return false;
        }
        return $term->broader()
            ? $this->ancestor($term, $level + 1)
            : $term;
    }
}
