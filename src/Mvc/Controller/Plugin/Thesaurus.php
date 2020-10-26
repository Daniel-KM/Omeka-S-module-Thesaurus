<?php
namespace Thesaurus\Mvc\Controller\Plugin;

use Doctrine\ORM\EntityManager;
use Omeka\Api\Adapter\ItemAdapter;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Mvc\Controller\Plugin\Api;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;

/**
 * @todo Implement a tree iterator.
 */
class Thesaurus extends AbstractPlugin
{
    protected $maxAncestors = 100;

    const VOCABULARY_NAMESPACE = 'http://www.w3.org/2004/02/skos/core#';
    const VOCABULARY_PREFIX = 'skos';
    const ROOT_CLASS = 'skos:ConceptScheme';
    const ITEM_CLASS = 'skos:Concept';
    const PARENT_TERM = 'skos:broader';
    const CHILD_TERM = 'skos:narrower';

    /**
     * @var bool
     */
    protected $returnItem = false;

    /**
     * @var array
     */
    protected $terms = [];

    /**
     * Cache all parents, children and titles of all terms, by id.
     *
     * @todo Check internationalized title.
     *
     * @var array
     */
    protected $structure = [];

    /**
     * List of tops by id.
     *
     * @var array
     */
    protected $tops = [];

    /**
     * @var ItemRepresentation
     */
    protected $item;

    /**
     * @var int
     */
    protected $itemId;

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
     * @param ItemAdapter
     */
    protected $itemAdapter;

    /**
     * @param Api
     */
    protected $api;

    /**
     * @param EntityManager $entityManager
     * @param ItemAdapter $itemAdapter
     * @param Api $api
     */
    public function __construct(EntityManager $entityManager, ItemAdapter $itemAdapter, Api $api)
    {
        $this->entityManager = $entityManager;
        $this->itemAdapter = $itemAdapter;
        $this->api = $api;
    }

    /**
     * Manage a thesaurus.
     *
     * @param ItemRepresentation $item
     * @return self
     */
    public function __invoke(ItemRepresentation $item)
    {
        $this->item = $item;
        $this->itemId = $item->id();
        $this->scheme = null;
        $this->isSkos = null;
        $this->isScheme = null;
        $this->isConcept = null;
        $this->isCollection = null;
        $this->isOrderedCollection = null;
        return $this
            ->cacheTerms()
            ->cacheStructure();
    }

    /**
     * If true, return item representations, else  a small array of term data.
     *
     * The method scheme() always returns an item.
     * It is not recommended to return items with big thesaurus.
     *
     * @deprecated Use self::itemFromData() instead, in particular for big thesaurus.
     * @param bool $returnItem
     * @return \Thesaurus\Mvc\Controller\Plugin\Thesaurus
     */
    public function setReturnItem($returnItem = false)
    {
        $this->returnItem = (bool) $returnItem;
        return $this;
    }

    /**
     * Helper to get the item representation from item data.
     *
     * @param array $itemData
     * @return ItemRepresentation|null
     */
    public function itemFromData(array $itemData = null)
    {
        return $itemData
            ? $this->api->searchOne('items', ['id' => $itemData['id']], ['initialize' => false, 'finalize' => false])->getContent()
            : null;
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
     * @return ItemRepresentation[]|array
     */
    public function tops()
    {
        return $this->returnFromData($this->tops);
    }

    /**
     * Check if a concept is a root (top concept).
     *
     * @return bool
     */
    public function isRoot()
    {
        return $this->isSkos
            && $this->structure[$this->itemId]['top'];
    }

    /**
     * Get the root concept of this item.
     *
     * @todo Check performance to get the root concept.
     *
     * @return ItemRepresentation|[]|null
     */
    public function root()
    {
        return $this->isSkos
            ? $this->returnFromData($this->ancestor($this->structure[$this->itemId]))
            : null;
    }

    /**
     * Get the broader concept of this item.
     *
     * @return ItemRepresentation|[]|null
     */
    public function broader()
    {
        return $this->isSkos
            ? $this->returnFromData($this->parent($this->structure[$this->itemId]))
            : null;
    }

    /**
     * Get the narrower concepts of this item.
     *
     * @return ItemRepresentation[]|array
     */
    public function narrowers()
    {
        return $this->isSkos
            ? $this->returnFromData($this->children($this->structure[$this->itemId]))
            : [];
    }

    /**
     * Get the related concepts of this item.
     *
     * @return ItemRepresentation[]|array
     */
    public function relateds()
    {
        if (!$this->isSkos) {
            return [];
        }
        return $this->returnItem
            ? $this->resourcesItemsFromValue($this->structure[$this->item], 'skos:related')
            : $this->resourcesFromValue($this->structure[$this->itemId], 'skos:related');
    }

    /**
     * Get the related concepts of this item, with self.
     *
     * @return ItemRepresentation[]|array
     */
    public function relatedsOrSelf()
    {
        if (!$this->isSkos) {
            return [];
        }
        $list = $this->relateds();
        $list[$this->itemId] = $this->returnItem ? $this->item : $this->structure[$this->itemId];
        return $list;
    }

    /**
     * Get the sibling concepts of this item (self not included).
     *
     * @return ItemRepresentation[]|array
     */
    public function siblings()
    {
        if (!$this->isSkos) {
            return [];
        }
        $result = $this->siblingsOrSelf();
        unset($result[$this->itemId]);
        return $result;
    }

    /**
     * Get the sibling concepts of this item (self included).
     *
     * @return ItemRepresentation[]|array
     */
    public function siblingsOrSelf()
    {
        if (!$this->isSkos) {
            return [];
        }
        if ($this->isRoot()) {
            return $this->tops();
        }
        // Don't use $this->broader() in order to keep data.
        $broader = $this->parent($this->structure[$this->itemId]);
        return $broader
            ? $this->returnFromData($this->children($broader))
            : [];
    }

    /**
     * Get the list of ascendants of this item, from closest to top concept.
     *
     * @return ItemRepresentation[]|array
     */
    public function ascendants()
    {
        return $this->isSkos
            ? $this->returnFromData($this->ancestors($this->structure[$this->itemId]))
            : [];
    }

    /**
     * Get the list of ascendants of this item, from self to top concept.
     *
     * @return ItemRepresentation[]|array
     */
    public function ascendantsOrSelf()
    {
        if (!$this->isSkos) {
            return [];
        }
        return [$this->itemId => $this->returnItem ? $this->item : $this->structure[$this->itemId]]
            + $this->ascendants();
    }

    /**
     * Get the list of descendants of this item.
     *
     * @return ItemRepresentation[]|array
     */
    public function descendants()
    {
        return $this->isSkos
            ? $this->returnFromData($this->listDescendants($this->structure[$this->itemId]))
            : [];
    }

    /**
     * Get the list of descendants of this item, with self last.
     *
     * @return ItemRepresentation[]|array
     */
    public function descendantsOrSelf()
    {
        if (!$this->isSkos) {
            return [];
        }
        $list = $this->descendants();
        $list[$this->itemId] = $this->returnItem ? $this->item : $this->structure[$this->itemId];
        return $list;
    }

    /**
     * Get the hierarchy of this item from the root (top concepts).
     *
     * @return array
     */
    public function tree()
    {
        if (!$this->isSkos) {
            return [];
        }
        $result = [];
        if ($this->returnItem) {
            foreach ($this->tops() as $item) {
                $result[$item->id()] = [
                    'self' => $item,
                    // TODO The other branch should check if the item is not set in another branch previously.
                    'children' => $this->recursiveBranchItems($item),
                ];
            }
            return $result;
        }
        foreach ($this->tops() as $itemData) {
            $result[$itemData['id']] = [
                'self' => $itemData,
                // TODO The other branch should check if the item is not set in another branch previously.
                'children' => $this->recursiveBranch($itemData),
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
        if (!$this->isSkos) {
            return [];
        }
        $result = [];
        if ($this->returnItem) {
            $result[$this->itemId] = [
                'self' => $this->item,
                'children' => $this->recursiveBranchItems($this->item),
            ];
            return $result;
        }
        $result[$this->itemId] = [
            'self' => $this->structure[$this->itemId],
            'children' => $this->recursiveBranch($this->structure[$this->itemId]),
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
        if (!$this->isSkos) {
            return [];
        }
        $result = [];
        if ($this->returnItem) {
            foreach ($this->tops() as $item) {
                $result[$item->id()] = [
                    'self' => $item,
                    'level' => 0,
                ];
                $result = $this->recursiveFlat($this->structure[$this->item->id()], $result, 1);
            }
            return $result;
        }
        foreach ($this->tops() as $itemData) {
            $result[$itemData['id']] = [
                'self' => $itemData,
                'level' => 0,
            ];
            $result = $this->recursiveFlatBranch($itemData, $result, 1);
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
        if (!$this->isSkos) {
            return [];
        }
        $result = [];
        $result[$this->itemId] = [
            'self' => $this->returnItem ? $this->item : $this->structure[$this->itemId],
            'level' => 0,
        ];
        return $this->recursiveFlatBranch($this->structure[$this->itemId], $result, 1);
    }

    /**
     * Get the list of terms or items by id from the root (top concept).
     *
     * This output is recommended for a select element form (terms).
     *
     * @param array $options May be: Indent, prepend_id.
     * @return array
     */
    public function listTree(array $options = null)
    {
        $result = $this->flatTree();
        return $this->list($result, is_null($options) ? [] : $options);
    }

    /**
     * Get the list of terms or items by id from this item.
     *
     * This output is recommended for a select element form (terms).
     *
     * @param array $options May be: Indent, prepend_id.
     * @return array
     */
    public function listBranch(array $options = null)
    {
        $result = $this->flatBranch();
        return $this->list($result, is_null($options) ? [] : $options);
    }

    /**
     * Get the list of terms or items by id from item data.
     *
     * This output is recommended for a select element form (terms).
     *
     * @todo Add option group: Get tops terms as group for a grouped select.
     *
     * @param array $options Only valable for term output.
     * - indent (string): String like "- " to prepend to terms to show level.
     * - prepend_id (bool): Prepend the id of the terms.
     * - max_length (int): Max size of the terms.
     * @return array
     */
    protected function list(array $list, array $options)
    {
        if ($this->returnItem) {
            return array_combine(array_keys($list), array_column($list, 'self'));
        }
        $options += [
            'indent' => '',
            'prepend_id' => false,
            'max_length' => 0,
        ];
        if (mb_strlen($options['indent'])) {
            $indent = $options['indent'];
            $list = array_map(function ($term) use ($indent) {
                if ($term['level']) {
                    $term['self']['title'] = str_repeat($indent, $term['level']) . ' ' . $term['self']['title'];
                }
                return $term;
            }, $list);
        }
        if ($options['prepend_id']) {
            $list = array_map(function ($term) {
                $term['self']['title'] = $term['self']['id'] . ': ' . $term['self']['title'];
                return $term;
            }, $list);
        }
        if ($options['max_length']) {
            $maxLength = $options['max_length'];
            $list = array_map(function ($term) use ($maxLength) {
                return mb_substr($term['self']['title'], 0, $maxLength);
            }, $list);
        } else {
            $list = array_map(function ($term) {
                return $term['self']['title'];
            }, $list);
        }
        return $list;
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
     * @param array $itemData
     * @param string $term
     * @return array
     */
    protected function resourcesFromValue(array $itemData, $term)
    {
        $item = $this->itemFromData($itemData);
        return array_map(function ($v) {
            return $this->structure[$v];
        }, $this->resourcesItemsFromValue($item, $term));
    }

    /**
     * Get all linked resources of this item for a term.
     *
     * @param ItemRepresentation $item
     * @param string $term
     * @return ItemRepresentation[]
     */
    protected function resourcesItemsFromValue(ItemRepresentation $item, $term)
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
     * @param array $itemData
     * @return array|null
     */
    protected function parent(array $itemData)
    {
        $parent = $this->structure[$itemData['id']]['parent'];
        return $parent ? $this->structure[$parent] : null;
    }

    /**
     * Get the narrower items of an item.
     *
     * @param array $itemData
     * @return array
     */
    protected function children(array $itemData)
    {
        return array_map(function ($v) {
            return $this->structure[$v];
        }, $this->structure[$itemData['id']]['children']);
    }

    /**
     * Recursive method to get the top concept of an item.
     *
     * @param array $itemData
     * @param int $level
     * @return array
     */
    protected function ancestor(array $itemData, $level = 0)
    {
        if ($level > $this->maxAncestors) {
            throw new \Omeka\Api\Exception\BadResponseException(sprintf(
                'There cannot be more than %d ancestors.', // @translate
                $this->maxAncestors
            ));
        }
        $parent = $this->parent($itemData);
        return $parent
            ? $this->ancestor($parent, $level + 1)
            : $itemData;
    }

    /**
     * Recursive method to get the ancestors of an item.
     *
     * @param array $itemData
     * @param array $list Internal param for recursive process.
     * @param int $level
     * @return array
     */
    protected function ancestors(array $itemData, array $list = [], $level = 0)
    {
        if ($level > $this->maxAncestors) {
            throw new \Omeka\Api\Exception\BadResponseException(sprintf(
                'There cannot be more than %d ancestors.', // @translate
                $this->maxAncestors
            ));
        }
        $parent = $this->parent($itemData);
        if ($parent) {
            $list[$parent['id']] = $parent;
            return $this->ancestors($parent, $list);
        }
        return $list;
    }

    /**
     * Recursive method to get the descendants of an item.
     *
     * @param array $itemData
     * @param array $list Internal param for recursive process.
     * @param int $level
     * @return array
     */
    protected function listDescendants(array $itemData, array $list = [], $level = 0)
    {
        if ($level > $this->maxAncestors) {
            throw new \Omeka\Api\Exception\BadResponseException(sprintf(
                'There cannot be more than %d levels of descendants.', // @translate
                $this->maxAncestors
            ));
        }
        $children = $this->children($itemData);
        foreach ($children as $child) {
            if (!isset($list[$child['id']])) {
                $list[$child['id']] = $child;
                $list += $this->listDescendants($child, $list, $level + 1);
            }
        }
        return $list;
    }

    /**
     * Recursive method to get the descendant tree of an item.
     *
     * @param array $itemData
     * @param array $branch Internal param for recursive process.
     * @param int $level
     * @return array
     */
    protected function recursiveBranch($itemData, array $branch = [], $level = 0)
    {
        if ($level > $this->maxAncestors) {
            throw new \Omeka\Api\Exception\BadResponseException(sprintf(
                'There cannot be more than %d levels of descendants.', // @translate
                $this->maxAncestors
            ));
        }
        $children = $this->children($itemData);
        foreach ($children as $child) {
            if (!isset($branch[$child['id']])) {
                $branch[$child['id']] = [
                    'self' => $child,
                    'children' => $this->recursiveBranch($child, [], $level + 1),
                ];
            }
        }
        return $branch;
    }

    /**
     * Recursive method to get the descendant tree of an item.
     *
     * @param ItemRepresentation $item
     * @param array $branch Internal param for recursive process.
     * @param int $level
     * @return array
     */
    protected function recursiveBranchItems(ItemRepresentation $item, array $branch = [], $level = 0)
    {
        if ($level > $this->maxAncestors) {
            throw new \Omeka\Api\Exception\BadResponseException(sprintf(
                'There cannot be more than %d levels of descendants.', // @translate
                $this->maxAncestors
            ));
        }
        $children = $this->children($this->structure[$item->id()]);
        foreach ($children as $child) {
            if (!isset($branch[$child['id']])) {
                $id = $child['id'];
                $child = $this->itemFromData($child);
                $branch[$id] = [
                    'self' => $child,
                    'children' => $this->recursiveBranchItems($child, [], $level + 1),
                ];
            }
        }
        return $branch;
    }

    /**
     * Recursive method to get the flat descendant tree of an item.
     *
     * @param array $itemData
     * @param array $branch Internal param for recursive process.
     * @param int $level
     * @return array
     */
    protected function recursiveFlatBranch(array $itemData, array $branch = [], $level = 0)
    {
        if ($level > $this->maxAncestors) {
            throw new \Omeka\Api\Exception\BadResponseException(sprintf(
                'There cannot be more than %d levels of descendants.', // @translate
                $this->maxAncestors
            ));
        }
        $children = $this->children($itemData);
        foreach ($children as $child) {
            if (!isset($branch[$child['id']])) {
                $branch[$child['id']] = [
                    'self' => $this->returnItem ? $this->itemFromData($child) : $child,
                    'level' => $level,
                ];
                $branch = $this->recursiveFlatBranch($child, $branch, $level + 1);
            }
        }
        return $branch;
    }

    /**
     * @return self
     */
    protected function cacheTerms()
    {
        if (count($this->terms)) {
            return $this;
        }

        $this->terms = [
            'class' => [],
            'property' => [],
        ];
        $values = $this->api
            ->search('resource_classes', ['vocabulary_prefix' => self::VOCABULARY_PREFIX])
            ->getContent();
        foreach ($values as $value) {
            $this->terms['class'][$value->term()] = $value->id();
        }

        $values = $this->api
            ->search('properties', ['vocabulary_prefix' => self::VOCABULARY_PREFIX])
            ->getContent();
        foreach ($values as $value) {
            $this->terms['property'][$value->term()] = $value->id();
        }

        return $this;
    }

    /**
     * @return self
     */
    protected function cacheStructure()
    {
        $this->structure = [];

        // Get thesaurus.
        if (!$this->scheme()) {
            $this->isSkos = false;
            $this->isScheme = false;
            $this->isConcept = false;
            $this->isCollection = false;
            $this->isOrderedCollection = false;
            return $this;
        }

        // Get all ids via api.
        // This allows to check visibility and to get the full list of concepts.
        // The validity of top concepts is not checked.
        // TODO Check if it is useful to check visibility here.
        $concepts = $this->api
            ->search(
                'items',
                [
                    'resource_class_id' => [
                        $this->terms['class']['skos:Concept'],
                    ],
                    'property' => [
                        [
                            'joiner' => 'and',
                            'property' => $this->terms['property']['skos:inScheme'],
                            'type' => 'res',
                            'text' => $this->scheme->id(),
                        ],
                    ],
                ],
                [
                    'initialize' => false,
                    'returnScalar' => 'id',
                ]
            )
            ->getContent();
        // TODO This check is useless: there is at least the item, else there is an issue (item is not visible?).
        if (!count($concepts)) {
            return $this;
        }

        // TODO Add the root and siblings.
        $this->structure = array_fill_keys($concepts, ['id' => null, 'title' => null, 'top' => false, 'parent' => null, 'children' => []]);

        $qb = $this->entityManager->createQueryBuilder();
        $expr = $qb->expr();

        // Get all titles.
        $qb
            ->select([
                'item.id',
                // Title is a resource key that is automatically managed by doctrine.
                'item.title',
            ])
            ->from(\Omeka\Entity\Item::class, 'item')
            ->leftJoin(\Omeka\Entity\Value::class, 'value', \Doctrine\ORM\Query\Expr\Join::WITH, $expr->eq('value.property', ':propertyInScheme'))
            ->andWhere($expr->eq('item.resourceClass', ':concept'))
            // Do not put in left join.
            ->andWhere($expr->eq('value.valueResource', ':scheme'))
            ->groupBy('item.id')
            ->addOrderBy('item.id', 'ASC')
            ->setParameters([
                'propertyInScheme' => $this->terms['property']['skos:inScheme'],
                'scheme' => $this->scheme->id(),
                'concept' => $this->terms['class']['skos:Concept'],
            ])
        ;
        $titles = array_column($qb->getQuery()->getScalarResult(), 'title', 'id');

        // Get all parents.
        $qb = $this->entityManager->createQueryBuilder()
            ->select([
                'item.id',
                // There is only zero or one parent, but this is a grouped query.
                'GROUP_CONCAT(DISTINCT IDENTITY(value_list.valueResource)) AS ids',
            ])
            ->from(\Omeka\Entity\Item::class, 'item')
            ->leftJoin(\Omeka\Entity\Value::class, 'value', \Doctrine\ORM\Query\Expr\Join::WITH, $expr->eq('value.property', ':propertyInScheme'))
            ->andWhere($expr->eq('item.resourceClass', ':concept'))
            ->andWhere($expr->eq('value.valueResource', ':scheme'))
            ->leftJoin(\Omeka\Entity\Value::class, 'value_list', \Doctrine\ORM\Query\Expr\Join::WITH, $expr->eq('value_list.property', ':propertyBroader'))
            ->andWhere($expr->eq('value_list.resource', 'item.id'))
            ->groupBy('item.id')
            ->addOrderBy('item.id', 'ASC')
            ->setParameters([
                'propertyInScheme' => $this->terms['property']['skos:inScheme'],
                'propertyBroader' => $this->terms['property']['skos:broader'],
                'concept' => $this->terms['class']['skos:Concept'],
                'scheme' => $this->scheme->id(),
            ])
        ;
        $parents = array_column($qb->getQuery()->getScalarResult(), 'ids', 'id');

        // Get all children.
        $qb = $this->entityManager->createQueryBuilder()
            ->select([
                'item.id',
                'GROUP_CONCAT(DISTINCT IDENTITY(value_list.valueResource) ORDER BY value_list.id) AS ids',
            ])
            ->from(\Omeka\Entity\Item::class, 'item')
            ->leftJoin(\Omeka\Entity\Value::class, 'value', \Doctrine\ORM\Query\Expr\Join::WITH, $expr->eq('value.property', ':propertyInScheme'))
            ->andWhere($expr->eq('item.resourceClass', ':concept'))
            ->andWhere($expr->eq('value.valueResource', ':inScheme'))
            ->leftJoin(\Omeka\Entity\Value::class, 'value_list', \Doctrine\ORM\Query\Expr\Join::WITH, $expr->eq('value_list.property', ':propertyNarrower'))
            ->andWhere($expr->eq('value_list.resource', 'item.id'))
            ->groupBy('item.id')
            ->addOrderBy('item.id', 'ASC')
            ->setParameters([
                'propertyInScheme' => $this->terms['property']['skos:inScheme'],
                'propertyNarrower' => $this->terms['property']['skos:narrower'],
                'concept' => $this->terms['class']['skos:Concept'],
                'inScheme' => $this->scheme->id(),
            ])
        ;
        $children = array_column($qb->getQuery()->getScalarResult(), 'ids', 'id');

        foreach ($this->structure as $id => &$value) {
            $value['id'] = $id;
            if (array_key_exists($id, $titles)) {
                $value['title'] = $titles[$id];
            }
            if (array_key_exists($id, $parents)) {
                $value['parent'] = (int) strtok($parents[$id], ',');
            } else {
                $value['top'] = true;
            }
            if (array_key_exists($id, $children)) {
                $value['children'] = array_map('intval', explode(',', $children[$id]));
            }
            if ($value['top']) {
                $this->tops[$id] = $value;
            }
        }

        // Set the value isSkos, because it is used directly in many places.
        $this->isSkos();

        return $this;
    }

    protected function returnFromData(array $data)
    {
        if (!$this->returnItem || !count($data)) {
            return $data;
        }

        $isSingle = isset($data['id']);
        if ($isSingle) {
            $data = [$data];
        }

        $qb = $this->entityManager->createQueryBuilder();
        $qb
            ->select('item')
            ->from(\Omeka\Entity\Item::class, 'item')
            ->where($qb->expr()->in('item', ':ids'))
            ->setParameter('ids', array_keys($data), \Doctrine\DBAL\Types\Type::INTEGER)
        ;
        $result = $qb->getQuery()->getResult();
        foreach ($result as &$item) {
            $item = $this->adapter->getRepresentation($item);
        }

        return $isSingle
            ? reset($result)
            : $result;
    }
}
