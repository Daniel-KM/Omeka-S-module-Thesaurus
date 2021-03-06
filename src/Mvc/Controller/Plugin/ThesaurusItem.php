<?php declare(strict_types=1);
namespace Thesaurus\Mvc\Controller\Plugin;

use Doctrine\ORM\EntityManager;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Mvc\Controller\Plugin\Api;

/**
 * @todo Implement a tree iterator.
 * Methods that may return many items may get an out of memory error in case of
 * a big thesaurus (more than 1000 terms). Use Thesaurus instead.
 */
class ThesaurusItem extends AbstractPlugin
{
    protected $maxAncestors = 100;

    const ROOT_CLASS = 'skos:ConceptScheme';
    const ITEM_CLASS = 'skos:Concept';
    const PARENT_TERM = 'skos:broader';
    const CHILD_TERM = 'skos:narrower';

    /**
     * @var ItemRepresentation
     */
    protected $item;

    /**
     * @var ItemRepresentation
     */
    protected $scheme;

    /**
     * @var bool
     */
    protected $isSkos;

    /**
     * @var bool
     */
    protected $isScheme;

    /**
     * @var bool
     */
    protected $isConcept;

    /**
     * @var bool
     */
    protected $isCollection;

    /**
     * @var bool
     */
    protected $isOrderedCollection;

    /**
     * @param EntityManager
     */
    protected $entityManager;

    /**
     * @param Api
     */
    protected $api;

    /**
     * @param EntityManager $entityManager
     * @param Api $api
     */
    public function __construct(EntityManager $entityManager, Api $api)
    {
        $this->entityManager = $entityManager;
        $this->api = $api;
    }

    /**
     * Manage a thesaurus.
     *
     * @todo Build the lists and the tree without loading items.
     *
     * @param ItemRepresentation $item
     * @return self
     */
    public function __invoke(ItemRepresentation $item)
    {
        $this->item = $item;
        return $this;
    }

    /**
     * This item is a skos item if it has at a skos class or a skos property.
     *
     * Required properties when class is not set are skos:hasTopConcept for
     * scheme and inScheme for other classes.
     *
     * The terms are checked with the prefix "skos" only.
     * The class may not be a skos class in order to manage sub classes of it,
     * not directly managed by Omeka.
     *
     * @return bool
     */
    public function isSkos()
    {
        if (is_null($this->isSkos)) {
            $class = $this->resourceClassName($this->item);
            $this->isSkos = strpos($class, 'skos:') === 0;
            if (!$this->isSkos) {
                $skosProperties = [
                    'skos:hasTopConcept' => null,
                    'skos:inScheme' => null,
                ];
                $values = array_intersect_key($this->item->values(), $skosProperties);
                $this->isSkos = !empty($values);
            }
        }
        return $this->isSkos;
    }

    /**
     * This item is a scheme if it has the class ConceptScheme or a top concept.
     *
     * @return bool
     */
    public function isScheme()
    {
        if (is_null($this->isScheme)) {
            $this->isScheme = $this->resourceClassName($this->item) === self::ROOT_CLASS
                || isset($this->item->values()['skos:hasTopConcept']);
        }
        return $this->isScheme;
    }

    /**
     * This item is a concept if it has the class Concept or a required property
     * of a concept (skos:broader, skos:narrower or skos:topConceptOf).
     *
     * @return bool
     */
    public function isConcept()
    {
        if (is_null($this->isConcept)) {
            if ($this->resourceClassName($this->item) === self::ITEM_CLASS) {
                $this->isConcept = true;
            } else {
                $values = $this->item->values();
                $this->isConcept = isset($values[self::PARENT_TERM])
                    || isset($values[self::CHILD_TERM])
                    || isset($values['skos:topConceptOf']);
            }
        }
        return $this->isConcept;
    }

    /**
     * This item is a collection if it has class Collection or OrderedCollection
     * or properties skos:member or skos:memberList.
     *
     * Note: an OrderedCollection is a Collection.
     *
     * @param bool $strict
     * @return bool
     */
    public function isCollection($strict = false)
    {
        if (is_null($this->isCollection)) {
            if ($strict) {
                $this->isScheme = $this->resourceClassName($this->item) === 'skos:Collection'
                    || isset($this->item->values()['skos:member']);
            } else {
                $class = $this->resourceClassName($this->item);
                $this->isScheme = $class === 'skos:Collection'
                    || $class === 'skos:OrderedCollection'
                    || isset($this->item->values()['skos:member'])
                    || isset($this->item->values()['skos:memberList']);
            }
        }
        return $this->isCollection;
    }

    /**
     * This item is an ordered collection if it has the class OrderedCollection,
     * or a property skos:memberList.
     *
     * @return bool
     */
    public function isOrderedCollection()
    {
        if (is_null($this->isOrderedCollection)) {
            $this->isScheme = $this->resourceClassName($this->item) === 'skos:OrderedCollection'
                || isset($this->item->values()['skos:memberList']);
        }
        return $this->isOrderedCollection;
    }

    /**
     * Get the scheme of this item.
     *
     * @return ItemRepresentation|null
     */
    public function scheme()
    {
        if (is_null($this->scheme)) {
            $this->scheme = $this->isScheme()
                ? $this->item
                : $this->resourceFromValue($this->item, 'skos:inScheme');
        }
        return $this->scheme;
    }

    /**
     * Get the top concepts of the scheme.
     *
     * @return ItemRepresentation[]
     */
    public function tops()
    {
        if ($this->isScheme()) {
            return $this->resourcesFromValue($this->item, 'skos:hasTopConcept');
        }
        if ($this->isSkos()) {
            $scheme = $this->scheme();
            if ($scheme) {
                return $this->resourcesFromValue($this->scheme, 'skos:hasTopConcept');
            }
        }
        return [];
    }

    /**
     * Check if a concept is a root (top concept).
     *
     * @return bool
     */
    public function isRoot()
    {
        return (bool) $this->resourceFromValue($this->item, 'skos:topConceptOf');
    }

    /**
     * Get the root concept of this item.
     *
     * @todo Check performance to get the root concept.
     *
     * @return ItemRepresentation
     */
    public function root()
    {
        return $this->ancestor($this->item);
    }

    /**
     * Get the broader concept of this item.
     *
     * @return ItemRepresentation|null
     */
    public function broader()
    {
        return $this->parent($this->item);
    }

    /**
     * Get the narrower concepts of this item.
     *
     * @return ItemRepresentation[]
     */
    public function narrowers()
    {
        return $this->children($this->item);
    }

    /**
     * Get the related concepts of this item.
     *
     * @return ItemRepresentation[]
     */
    public function relateds()
    {
        return $this->resourcesFromValue($this->item, 'skos:related');
    }

    /**
     * Get the related concepts of this item, with self.
     *
     * @return ItemRepresentation[]
     */
    public function relatedsOrSelf()
    {
        $list = $this->resourcesFromValue($this->item, 'skos:related');
        $list[$this->item->id()] = $this->item;
        return $list;
    }

    /**
     * Get the sibling concepts of this item (self not included).
     *
     * @return ItemRepresentation[]
     */
    public function siblings()
    {
        $result = [];

        if ($this->isRoot()) {
            $scheme = $this->scheme();
            if ($scheme) {
                $result = $this->tops();
            } else {
                return $result;
            }
        } else {
            $broader = $this->broader();
            if ($broader) {
                $result = $this->children($broader);
            } else {
                return $result;
            }
        }

        $id = $this->item->id();
        foreach ($result as $key => $narrower) {
            if ($narrower->id() === $id) {
                unset($result[$key]);
                break;
            }
        }

        return $result;
    }

    /**
     * Get the sibling concepts of this item (self included).
     *
     * @return ItemRepresentation[]
     */
    public function siblingsOrSelf()
    {
        $result = [];

        if ($this->isRoot()) {
            $scheme = $this->scheme();
            if ($scheme) {
                $result = $this->tops();
            } else {
                return $result;
            }
        } else {
            $broader = $this->broader();
            if ($broader) {
                $result = $this->children($broader);
            } else {
                return $result;
            }
        }

        return $result;
    }

    /**
     * Get the list of ascendants of this item, from closest to top concept.
     *
     * @return ItemRepresentation[]
     */
    public function ascendants()
    {
        return $this->ancestors($this->item);
    }

    /**
     * Get the list of ascendants of this item, from self to top concept.
     *
     * @return ItemRepresentation[]
     */
    public function ascendantsOrSelf()
    {
        return [$this->item->id() => $this->item] + $this->ascendants();
    }

    /**
     * Get the list of descendants of this item.
     *
     * @return ItemRepresentation[]
     */
    public function descendants()
    {
        return $this->listDescendants($this->item);
    }

    /**
     * Get the list of descendants of this item, with self last.
     *
     * @return ItemRepresentation[]
     */
    public function descendantsOrSelf()
    {
        $list = $this->descendants();
        $list[$this->item->id()] = $this->item;
        return $list;
    }

    /**
     * Get the hierarchy of this item from the root (top concepts).
     *
     * @return array
     */
    public function tree()
    {
        $result = [];
        foreach ($this->tops() as $item) {
            $result[$item->id()] = [
                'self' => $item,
                // TODO The other branch should check if the item is not set in another branch previously.
                'children' => $this->recursiveBranch($item),
            ];
        }
        return $result;
    }

    /**
     * Get the hierarchy branch of this item, self included.
     *
     * @return array
     */
    public function branch()
    {
        $result = [];
        $result[$this->item->id()] = [
            'self' => $this->item,
            'children' => $this->recursiveBranch($this->item),
        ];
        return $result;
    }

    /**
     * Get the flat hierarchy of this item from the root (top concept).
     *
     * @return array
     */
    public function flatTree()
    {
        $result = [];
        foreach ($this->tops() as $item) {
            $result[$item->id()] = [
                'self' => $item,
                'level' => 0,
            ];
            $result = $this->recursiveFlatBranch($item, $result, 1);
        }
        return $result;
    }

    /**
     * Get the flat hierarchy branch of this item, self included.
     *
     * @return array
     */
    public function flatBranch()
    {
        $result = [];
        $result[$this->item->id()] = [
            'self' => $this->item,
            'level' => 0,
        ];
        return $this->recursiveFlatBranch($this->item, $result, 1);
    }

    /**
     * Get the name of the current resource class.
     *
     * @param ItemRepresentation $item
     * @return string
     */
    protected function resourceClassName(ItemRepresentation $item)
    {
        $resourceClass = $item->resourceClass();
        return $resourceClass
            ? $resourceClass->term()
            : '';
    }

    /**
     * Get a linked resource of this item for a term.
     *
     * @param ItemRepresentation $item
     * @param string $term
     * @return ItemRepresentation|null
     */
    protected function resourceFromValue(ItemRepresentation $item, $term)
    {
        $values = $item->values();
        if (isset($values[$term])) {
            /** @var \Omeka\Api\Representation\ValueRepresentation $value */
            foreach ($values[$term]['values'] as $value) {
                if (in_array($value->type(), ['resource', 'resource:item'])) {
                    return $value->valueResource();
                }
            }
        }
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
        return $result;
    }

    /**
     * Get the broader item of an item.
     *
     * @param ItemRepresentation $item
     * @return ItemRepresentation|null
     */
    protected function parent(ItemRepresentation $item)
    {
        return $this->resourceFromValue($item, self::PARENT_TERM);
    }

    /**
     * Get the narrower items of an item.
     *
     * @param ItemRepresentation $item
     * @return ItemRepresentation[]
     */
    protected function children(ItemRepresentation $item)
    {
        return $this->resourcesFromValue($item, self::CHILD_TERM);
    }

    /**
     * Recursive method to get the top concept of an item.
     *
     * @param ItemRepresentation $item
     * @param int $level
     * @return ItemRepresentation
     */
    protected function ancestor(ItemRepresentation $item, $level = 0)
    {
        if ($level > $this->maxAncestors) {
            throw new \Omeka\Api\Exception\BadResponseException(sprintf(
                'There cannot be more than %d ancestors.', // @translate
                $this->maxAncestors
            ));
        }
        $parent = $this->parent($item);
        return $parent
            ? $this->ancestor($parent, $level + 1)
            : $item;
    }

    /**
     * Recursive method to get the ancestors of an item.
     *
     * @param ItemRepresentation $item
     * @param array $list Internal param for recursive process.
     * @param int $level
     * @return ItemRepresentation[]
     */
    protected function ancestors(ItemRepresentation $item, array $list = [], $level = 0)
    {
        if ($level > $this->maxAncestors) {
            throw new \Omeka\Api\Exception\BadResponseException(sprintf(
                'There cannot be more than %d ancestors.', // @translate
                $this->maxAncestors
            ));
        }
        $parent = $this->parent($item);
        if ($parent) {
            $list[$parent->id()] = $parent;
            return $this->ancestors($parent, $list);
        }
        return $list;
    }

    /**
     * Recursive method to get the descendants of an item.
     *
     * @param ItemRepresentation $item
     * @param array $list Internal param for recursive process.
     * @param int $level
     * @return ItemRepresentation[]
     */
    protected function listDescendants(ItemRepresentation $item, array $list = [], $level = 0)
    {
        if ($level > $this->maxAncestors) {
            throw new \Omeka\Api\Exception\BadResponseException(sprintf(
                'There cannot be more than %d levels of descendants.', // @translate
                $this->maxAncestors
            ));
        }
        $children = $this->children($item);
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
     * Recursive method to get the descendant tree of an item.
     *
     * @param ItemRepresentation $item
     * @param array $branch Internal param for recursive process.
     * @param int $level
     * @return array
     */
    protected function recursiveBranch(ItemRepresentation $item, array $branch = [], $level = 0)
    {
        if ($level > $this->maxAncestors) {
            throw new \Omeka\Api\Exception\BadResponseException(sprintf(
                'There cannot be more than %d levels of descendants.', // @translate
                $this->maxAncestors
            ));
        }
        $children = $this->children($item);
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
     * Recursive method to get the flat descendant tree of an item.
     *
     * @param ItemRepresentation $item
     * @param array $branch Internal param for recursive process.
     * @param int $level
     * @return array
     */
    protected function recursiveFlatBranch(ItemRepresentation $item, array $branch = [], $level = 0)
    {
        if ($level > $this->maxAncestors) {
            throw new \Omeka\Api\Exception\BadResponseException(sprintf(
                'There cannot be more than %d levels of descendants.', // @translate
                $this->maxAncestors
            ));
        }
        $children = $this->children($item);
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
