<?php declare(strict_types=1);

namespace Thesaurus\Api\Representation;

use Omeka\Api\Representation\AbstractEntityRepresentation;

/**
 * @todo Make TermRepresentation an AbstractResourceEntityRepresentation.
 */
class TermRepresentation extends AbstractEntityRepresentation
{
    protected $maxAncestors = 100;

    public function getJsonLdType()
    {
        return 'skos:Concept';
    }

    public function getJsonLd()
    {
        $scheme = $this->scheme()->getReference();
        $json = [
            'o:item' => $this->item()->getReference(),
            'skos:inScheme' => $scheme,
        ];
        $broader = $this->broader();
        if ($broader) {
            $json['skos:broader'] = $broader->getReference();
        } else {
            $json['skos:topConceptOf'] = $scheme;
        }
        $narrowers = $this->resource->getNarrowers();
        if (count($narrowers)) {
            $json['skos:narrowers'] = [];
            $adapter = $this->getAdapter();
            foreach ($narrowers as $entity) {
                $json['skos:narrowers'][] = $adapter->getReference($entity);
            }
        }
        return $json;
    }

    /**
     * Get the item associated to this term.
     *
     * @return \Omeka\Api\Representation\ItemRepresentation
     */
    public function item()
    {
        return $this->getAdapter('items')
            ->getRepresentation($this->resource->getItem());
    }

    /**
     * Get the item that is the scheme of this term.
     *
     * @return \Omeka\Api\Representation\ItemRepresentation
     */
    public function scheme()
    {
        return $this->getAdapter('items')
            ->getRepresentation($this->resource->getScheme());
    }

    /**
     * Get the top terms of the scheme.
     *
     * @return \Thesaurus\Api\Representation\TermRepresentation[]
     */
    public function tops()
    {
        $adapter = $this->getAdapter();
        $entityManager = $adapter->getEntityManager();
        $connection = $entityManager->getConnection();
        $qb = $entityManager->createQueryBuilder();

        $expr = $qb->expr();

        $qb
            ->select('omeka_root')
            ->from(\Thesaurus\Entity\Term::class, 'omeka_root')
            ->where($expr->eq('scheme', ':scheme'))
            ->setParameter('scheme', $this->resource->getScheme())
            ->andWhere($expr->isNull('broader'))
            // Or:
            // ->andWhere($expr->eq('id', 'root'))
            ->orderBy('position')
        ;
        $result = $connection->executeQuery($qb, $qb->getParameters())->fetchAll();

        $tops = [];
        foreach ($result as $entity) {
            $tops[$entity->getId()] = $adapter->getRepresentation($entity);
        }
        return $tops;
    }

    /**
     * Get the root term of this term.
     *
     * @return \Thesaurus\Api\Representation\TermRepresentation
     */
    public function root()
    {
        $root = $this->resource->getRoot();
        return $this->getAdapter()
            ->getRepresentation($root);
    }

    /**
     * Check if this term is a root (top concept) in the scheme.
     *
     * @return bool
     */
    public function isRoot()
    {
        return $this->resource->getRoot()->getId() === $this->id();
    }

    /**
     * Get the broader term of this term.
     *
     * @return \Thesaurus\Api\Representation\TermRepresentation|null
     */
    public function broader()
    {
        $broader = $this->resource->getBroader();
        if (!$broader) {
            return null;
        }
        return $this->getAdapter()
            ->getRepresentation($broader);
    }

    /**
     * Get the narrower terms of this term.
     *
     * @return \Thesaurus\Api\Representation\TermRepresentation[]
     */
    public function narrowers()
    {
        $narrowers = [];
        $adapter = $this->getAdapter();
        foreach ($this->resource->getNarrowers() as $entity) {
            $narrowers[$entity->getId()] = $adapter->getRepresentation($entity);
        }
        return $narrowers;
    }

    /**
     * Get the sibling terms of this term (self not included).
     *
     * @return \Thesaurus\Api\Representation\TermRepresentation[]
     */
    public function siblings()
    {
        $siblingsOrSelf = $this->siblingsOrSelf();
        unset($siblingsOrSelf[$this->resource->getId()]);
        return $siblingsOrSelf;
    }

    /**
     * Get the sibling terms of this item (self included).
     *
     * @return \Thesaurus\Api\Representation\TermRepresentation[]
     */
    public function siblingsOrSelf()
    {
        $result = [];
        $adapter = $this->getAdapter();
        $broader = $this->resource->getBroader();
        if ($broader) {
            $siblingsOrSelf = $broader->getNarrowers();
            foreach ($siblingsOrSelf as $entity) {
                $result[$entity->getId()] = $adapter->getRepresentation($entity);
            }
            return $result;
        } else {
            return $this->tops() ;
        }
    }

    /**
     * Get the list of ascendants of this term, from closest to top term.
     *
     * @return TermRepresentation[]
     */
    public function ascendants(bool $fromTop = false): array
    {
        $result = $this->ancestors($this);
        return $fromTop
            ? array_reverse($result, true)
            : $result;
    }

    /**
     * Get the list of ascendants of this term, from self to top term.
     *
     * @return TermRepresentation[]
     */
    public function ascendantsOrSelf(bool $fromTop = false): array
    {
        return $fromTop
            ? $this->ascendants(true) + [$this->id() => $this]
            : ([$this->id() => $this] + $this->ascendants());
    }

    /**
     * Get the list of descendants of this term.
     *
     * @return TermRepresentation[]
     */
    public function descendants()
    {
        return $this->listDescendants($this);
    }

    /**
     * Get the list of descendants of this term, with self last.
     *
     * @return TermRepresentation[]
     */
    public function descendantsOrSelf()
    {
        $list = $this->listDescendants($this);
        $list[$this->id()] = $this;
        return $list;
    }

    /**
     * Get the hierarchy of this term from the root (top term).
     *
     * @return array
     */
    public function tree()
    {
        $result = [];
        foreach ($this->tops() as $term) {
            $result[$term->id()] = [
                'self' => $term,
                'children' => $this->recursiveBranch($term),
            ];
        }
        return $result;
    }

    /**
     * Get the hierarchy branch of this term, self included.
     *
     * @return array
     */
    public function branch()
    {
        $result = [];
        $result[$this->id()] = [
            'self' => $this,
            'children' => $this->recursiveBranch($this),
        ];
        return $result;
    }

    /**
     * Get the flat hierarchy of this term from the root (top term).
     *
     * @return array
     */
    public function flatTree()
    {
        $result = [];
        foreach ($this->tops() as $term) {
            $result[$term->id()] = [
                'self' => $term,
                'level' => 0,
            ];
            $result = $this->recursiveFlatBranch($term, $result, 1);
        }
        return $result;
    }

    /**
     * Get the flat hierarchy branch of this term, self included.
     *
     * @return array
     */
    public function flatBranch()
    {
        $result = [];
        $result[$this->id()] = [
            'self' => $this,
            'level' => 0,
        ];
        return $this->recursiveFlatBranch($this, $result, 1);
    }

    /**
     * @todo Remove term position?
     *
     * @return int
     */
    public function position()
    {
        return $this->resource->getPosition();
    }

    public function thumbnail()
    {
        return $this->item()->thumbnail();
    }

    public function title()
    {
        return $this->item()->getTitle();
    }

    public function values()
    {
        return $this->item()->values();
    }

    public function value($term, array $options = [])
    {
        return $this->item()->value($term, $options);
    }

    public function displayTitle($default = null)
    {
        return $this->item()->displayTitle($default);
    }

    public function displayDescription($default = null)
    {
        return $this->item()->displayDescription($default);
    }

    /**
     * Currently, the term is manageable only as item.
     *
     * {@inheritDoc}
     * @see \Omeka\Api\Representation\AbstractResourceRepresentation::adminUrl()
     */
    public function adminUrl($action = null, $canonical = false)
    {
        $url = $this->getViewHelper('Url');
        return $url(
            'admin/id',
            [
                'controller' => 'items',
                'action' => $action,
                'id' => $this->id(),
            ],
            ['force_canonical' => $canonical]
        );
    }

    /**
     * Recursive method to get the ancestors of a term.
     *
     * @param TermRepresentation $term
     * @param array $list Internal param for recursive process.
     * @param int $level
     * @return TermRepresentation[]
     */
    protected function ancestors(TermRepresentation $term, array $list = [], $level = 0)
    {
        if ($level > $this->maxAncestors) {
            throw new \Omeka\Api\Exception\BadResponseException(sprintf(
                'There cannot be more than %d ancestors.', // @translate
                $this->maxAncestors
            ));
        }
        $parent = $term->broader();
        if ($parent) {
            $list[$parent->id()] = $parent;
            return $this->ancestors($parent, $list);
        }
        return $list;
    }

    /**
     * Recursive method to get the descendants of a term.
     *
     * @param TermRepresentation $item
     * @param array $list Internal param for recursive process.
     * @param int $level
     * @return TermRepresentation[]
     */
    protected function listDescendants(TermRepresentation $term, array $list = [], $level = 0)
    {
        if ($level > $this->maxAncestors) {
            throw new \Omeka\Api\Exception\BadResponseException(sprintf(
                'There cannot be more than %d levels of descendants.', // @translate
                $this->maxAncestors
            ));
        }
        $children = $term->narrowers();
        foreach ($children as $child) {
            $id = $child->id();
            if (!isset($list[$id])) {
                $list[$id] = $child;
                $list += $this->listDescendants($child, $list, $level + 1);
            }
        }
        return $list;
    }

    /**
     * Recursive method to get the descendant tree of a term.
     *
     * @param TermRepresentation $term
     * @param array $branch Internal param for recursive process.
     * @param int $level
     * @return array
     */
    protected function recursiveBranch(TermRepresentation $term, array $branch = [], $level = 0)
    {
        if ($level > $this->maxAncestors) {
            throw new \Omeka\Api\Exception\BadResponseException(sprintf(
                'There cannot be more than %d levels of descendants.', // @translate
                $this->maxAncestors
            ));
        }
        $children = $term->narrowers();
        foreach ($children as $child) {
            $id = $child->id();
            if (!isset($branch[$id])) {
                $branch[$id] = [
                    'self' => $child,
                    'children' => $this->recursiveBranch($child, [], $level + 1),
                ];
            }
        }
        return $branch;
    }

    /**
     * Recursive method to get the flat descendant tree of an term.
     *
     * @param TermRepresentation $term
     * @param array $branch Internal param for recursive process.
     * @param int $level
     * @return array
     */
    protected function recursiveFlatBranch(TermRepresentation $term, array $branch = [], $level = 0)
    {
        if ($level > $this->maxAncestors) {
            throw new \Omeka\Api\Exception\BadResponseException(sprintf(
                'There cannot be more than %d levels of descendants.', // @translate
                $this->maxAncestors
            ));
        }
        $children = $term->narrowers();
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
