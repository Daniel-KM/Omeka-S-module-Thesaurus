<?php declare(strict_types=1);

namespace Thesaurus\Mvc\Controller\Plugin;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Query\Parameter;
use Laminas\Log\Logger;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use Laminas\Mvc\Plugin\Identity\Identity;
use Omeka\Api\Adapter\ItemAdapter;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\ItemSetRepresentation;

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
     * @var ItemSetRepresentation|null|false
     */
    protected $itemSet;

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
     * @var bool
     */
    protected $isPublic;

    /**
     * @var EntityManager
     */
    protected $entityManager;

    /**
     * @var ItemAdapter
     */
    protected $itemAdapter;

    /**
     * @var ApiManager
     */
    protected $api;

    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @var ?\Omeka\Entity\User
     */
    protected $user;

    /**
     * @var string
     */
    protected $separator;

    public function __construct(
        EntityManager $entityManager,
        ItemAdapter $itemAdapter,
        ApiManager $api,
        Logger $logger,
        Identity $identity,
        string $separator
    ) {
        $this->entityManager = $entityManager;
        $this->itemAdapter = $itemAdapter;
        $this->api = $api;
        $this->logger = $logger;
        $this->user = $identity();
        $this->isPublic = empty($this->user)
            || $this->user->getRole() === 'guest';
        $this->separator = $separator;
    }

    /**
     * Manage a thesaurus.
     *
     * @param AbstractResourceEntityRepresentation|int|null $itemOrItemSetOrId
     *   The item should be a scheme or a concept. If it is an item set, it
     *   should be a skos collection or a skos ordered collection that contains
     *   a scheme, that wll be the item that will be set.
     *
     *   The thesaurus will be init with this concept or scheme. It will be used
     *   by default in other methods, for example to get ascendants or
     *   descendants.
     */
    public function __invoke($itemOrItemSetOrId = null): self
    {
        if (is_numeric($itemOrItemSetOrId)) {
            try {
                $itemOrItemSetOrId = $this->api->read('resources', ['id' => $itemOrItemSetOrId], ['initialize' => false])->getContent();
            } catch (\Exception $e) {
                $itemOrItemSetOrId = null;
            }
        }
        if ($itemOrItemSetOrId) {
            if ($itemOrItemSetOrId instanceof ItemSetRepresentation) {
                $class = $itemOrItemSetOrId->resourceClass();
                $classTerm = $class ? $class->term() : null;
                if (in_array($classTerm, ['skos:Collection', 'skos:OrderedCollection'])) {
                    $this->cacheTerms();
                    // TODO Api read with item set.
                    $itemOrItemSetOrId = $this->api->search('items', [
                        'item_set_id' => $itemOrItemSetOrId->id(),
                        'resource_class_id' => $this->terms['class'][self::ROOT_CLASS],
                        'limit' => 1,
                    ], ['initialize' => false])->getContent();
                    $itemOrItemSetOrId = count($itemOrItemSetOrId) ? reset($itemOrItemSetOrId) : null;
                } else {
                    $itemOrItemSetOrId = null;
                }
            } elseif (!$itemOrItemSetOrId instanceof ItemRepresentation) {
                $itemOrItemSetOrId = null;
            }
        }
        return $this->setItem($itemOrItemSetOrId);
    }

    /**
     * If true, return item representations, else a small array of term data.
     *
     * The method scheme() always returns an item.
     * It is not recommended to return items with big thesaurus.
     *
     * @deprecated Use self::itemFromData() instead, in particular for big thesaurus.
     */
    public function setReturnItem(bool $returnItem = false): self
    {
        $this->returnItem = (bool) $returnItem;
        return $this;
    }

    /**
     * Set another base item.
     *
     * If the item does not belong to the current thesaurus, the thesaurus is
     * reinitialized. If the item is empty, the thesaurus is reset.
     */
    public function setItem(?ItemRepresentation $item): self
    {
        $this->item = $item;
        return $this->init();
    }

    /**
     * Return the item used to build the thesaurus or the last item used.
     */
    public function getItem(): ?ItemRepresentation
    {
        return $this->item;
    }

    /**
     * Return the item set associated to this thesaurus.
     */
    public function getItemSet(): ?ItemSetRepresentation
    {
        if (!$this->isSkos()) {
            return null;
        }

        if (!is_null($this->itemSet)) {
            return $this->itemSet ?: null;
        }

        $this->itemSet = false;

        $scheme = $this->scheme();
        foreach ($scheme->itemSets() as $itemSet) {
            $class = $itemSet->resourceClass();
            if ($class && in_array($class->term(), ['skos:Collection', 'skos:OrderedCollection'])) {
                $this->itemSet = $itemSet;
                break;
            }
        }

        return $this->itemSet ?: null;
    }

    /**
     * Check if the specified item is in the thesaurus.
     *
     * @param ItemRepresentation|int $itemOrId
     */
    public function isInThesaurus($itemOrId = null): bool
    {
        if (empty($itemOrId)) {
            $id = $this->itemId;
        } else {
            $id = is_object($itemOrId) ? $itemOrId->id() : (int) $itemOrId;
        }
        return !empty($this->structure[$id]);
    }

    /**
     * Get the item representation from item data or id, or get current item.
     *
     * @param array|int|string $itemData
     * @return ItemRepresentation Return the current item when empty
     */
    public function itemFromData($itemData = null): ?ItemRepresentation
    {
        if (empty($itemData)) {
            return $this->item;
        } elseif (is_numeric($itemData)) {
            $id = (int) $itemData;
        } elseif (is_array($itemData)) {
            $id = $itemData['id'] ?? $itemData['self']['id'] ?? null;
        }
        if ($id) {
            try {
                return $this->api->read('items', ['id' => $id])->getContent();
            } catch (\Exception $e) {
                $this->logger->err(
                    sprintf('Thesaurus based on item #%s does not exist or is not available to current user.', // @translate
                    $id
                ));
            }
        }
        return null;
    }

    /**
     * Return the data for the item used to build the thesaurus or any item.
     *
     * @param ItemRepresentation|int $item
     */
    public function itemToData($itemOrId = null): ?array
    {
        if (empty($itemOrId)) {
            $id = $this->itemId;
        } else {
            $id = is_object($itemOrId) ? $itemOrId->id() : (int) $itemOrId;
        }
        if (empty($this->structure[$id])) {
            return null;
        }
        return [
            'self' => $this->structure[$id],
            'level' => count($this->ancestors($this->structure[$id])),
        ];
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
     */
    public function isSkos(): bool
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
     */
    public function isScheme(): bool
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
     */
    public function isConcept(): bool
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
     * Check if a concept is a top concept.
     */
    public function isTop(): bool
    {
        return $this->isSkos
            && !empty($this->structure[$this->itemId]['top']);
    }

    /**
     * Check if a concept is a root (top concept).
     *
     * @deprecated Use isTop() instead. Root is more like the scheme.
     * @see self:isTop()
     */
    public function isRoot(): bool
    {
        return $this->isTop();
    }

    /**
     * This item is a collection if it has class Collection or OrderedCollection
     * or properties skos:member or skos:memberList.
     *
     * Note: an OrderedCollection is a Collection.
     */
    public function isCollection(bool $strict = false): bool
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
     */
    public function isOrderedCollection(): bool
    {
        if (is_null($this->isOrderedCollection)) {
            $this->isScheme = $this->resourceClassName($this->item) === 'skos:OrderedCollection'
                || isset($this->item->values()['skos:memberList']);
        }
        return $this->isOrderedCollection;
    }

    /**
     * Get the current item as array (may be empty).
     */
    public function selfItem(): array
    {
        if ($this->returnItem) {
            return $this->item ? [$this->itemId => $this->item] : [];
        }
        // Normally it should be always set, but issue may occur.
        return isset($this->structure[$this->itemId])
            ? [$this->itemId => $this->structure[$this->itemId]]
            : [];
    }

    /**
     * Get the scheme of this item.
     */
    public function scheme(): ?ItemRepresentation
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
    public function tops(): array
    {
        return $this->returnFromData($this->tops);
    }

    /**
     * Get the top concept of this item, that may be itself.
     *
     * @todo Check performance to get the root concept.
     *
     * @return ItemRepresentation|array|null
     */
    public function top()
    {
        return $this->isSkos && $this->isConcept()
            ? $this->returnFromData($this->ancestor($this->structure[$this->itemId] ?? null))
            : null;
    }

    /**
     * Get the root concept of this item, that may be itself.
     *
     * @deprecated Use self::top() instead. Root is more like the scheme.
     * @uses self::top()
     * @return ItemRepresentation|array|null
     */
    public function root()
    {
        return $this->top();
    }

    /**
     * Get the broader concept of this item.
     *
     * @return ItemRepresentation|array|null
     */
    public function broader()
    {
        return $this->isSkos && $this->isConcept()
            ? $this->returnFromData($this->parent($this->structure[$this->itemId] ?? null))
            : null;
    }

    /**
     * Get the narrower concepts of this item.
     *
     * @return ItemRepresentation[]|array
     */
    public function narrowers(): array
    {
        return $this->isSkos && $this->isConcept()
            ? $this->returnFromData($this->children($this->structure[$this->itemId] ?? null))
            : [];
    }

    /**
     * Get the list of narrower concepts of this item, with self first.
     *
     * @return ItemRepresentation[]|array
     */
    public function narrowersOrSelf(): array
    {
        if (!$this->isSkos || $this->isScheme()) {
            return [];
        }
        return $this->selfItem()
            + $this->narrowers();
    }

    /**
     * Get the related concepts of this item.
     *
     * @return ItemRepresentation[]|array
     */
    public function relateds(): array
    {
        if (!$this->isSkos || $this->isScheme()) {
            return [];
        }
        return $this->returnItem
            ? $this->resourcesItemsFromValue($this->structure[$this->itemId] ?? null, 'skos:related')
            : $this->resourcesFromValue($this->structure[$this->itemId] ?? null, 'skos:related');
    }

    /**
     * Get the related concepts of this item, with self.
     *
     * @return ItemRepresentation[]|array
     */
    public function relatedsOrSelf(): array
    {
        if (!$this->isSkos || $this->isScheme()) {
            return [];
        }
        return $this->relateds()
            + $this->selfItem();
    }

    /**
     * Get the sibling concepts of this item (self not included).
     *
     * @return ItemRepresentation[]|array
     */
    public function siblings(): array
    {
        if (!$this->isSkos || $this->isScheme) {
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
    public function siblingsOrSelf(): array
    {
        if (!$this->isSkos || $this->isScheme()) {
            return [];
        }
        if ($this->isTop()) {
            return $this->tops();
        }
        // Don't use $this->broader() in order to keep data.
        $broader = $this->parent($this->structure[$this->itemId] ?? null);
        return $broader
            ? $this->returnFromData($this->children($broader))
            : [];
    }

    /**
     * Get the list of ascendants of this item, from closest to top concept.
     *
     * @return ItemRepresentation[]|array
     */
    public function ascendants(bool $fromTop = false): array
    {
        if (!$this->isSkos || !$this->isConcept()) {
            return [];
        }
        $result = $this->returnFromData($this->ancestors($this->structure[$this->itemId] ?? null));
        return $fromTop
            ? array_reverse($result, true)
            : $result;
    }

    /**
     * Get the list of ascendants of this item, from self to top concept.
     *
     * @return ItemRepresentation[]|array
     */
    public function ascendantsOrSelf(bool $fromTop = false): array
    {
        if (!$this->isSkos || $this->isScheme()) {
            return [];
        }
        return $fromTop
            ? $this->ascendants(true) + $this->selfItem()
            : ($this->selfItem() + $this->ascendants());
    }

    /**
     * Get the list of descendants of this item.
     *
     * @return ItemRepresentation[]|array
     */
    public function descendants(): array
    {
        return $this->isSkos && $this->isConcept()
            ? $this->returnFromData($this->listDescendants($this->structure[$this->itemId] ?? null))
            : [];
    }

    /**
     * Get the list of descendants of this item, with self first.
     *
     * @return ItemRepresentation[]|array
     */
    public function descendantsOrSelf(): array
    {
        if (!$this->isSkos || $this->isScheme()) {
            return [];
        }
        return $this->selfItem()
            + $this->descendants();
    }

    /**
     * Get the hierarchy of this item from the top concepts.
     */
    public function tree(): array
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
     * Get the hierarchy branch of this item from top concept, self included.
     */
    public function branch(): array
    {
        if (!$this->isSkos || $this->isScheme()) {
            return [];
        }
        $result = [];
        $top = $this->top();
        if ($this->returnItem) {
            $result[$top->id()] = [
                'self' => $top,
                'children' => $this->recursiveBranchItems($top),
            ];
            return $result;
        }
        $result[$top['id']] = [
            'self' => $this->structure[$top['id']],
            'children' => $this->recursiveBranch($this->structure[$top['id']]),
        ];
        return $result;
    }

    /**
     * Get the hierarchy branch of this item without top concept, self included,
     * except if it is the top.
     */
    public function branchNoTop(): array
    {
        $branch = $this->branch();
        $branch = reset($branch);
        return $branch ? $branch['children'] : [];
    }

    /**
     * Get the hierarchy branch from this item, so self and descendants as tree.
     */
    public function branchFromItem(): array
    {
        if (!$this->isSkos || $this->isScheme()) {
            return [];
        }
        if ($this->isTop()) {
            return $this->branch();
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
            'self' => $this->structure[$this->itemId] ?? null,
            'children' => $this->recursiveBranch($this->structure[$this->itemId] ?? null),
        ];
        return $result;
    }

    /**
     * Get the hierarchy branch below this item, so descendants as a tree.
     */
    public function branchBelowItem(): array
    {
        $branch = $this->branchFromItem();
        if (empty($branch)) {
            return [];
        }
        $branch = reset($branch);
        return $branch['children'] ?? [];
    }

    /**
     * Get the flat hierarchy of this item from the top concepts.
     */
    public function flatTree(): array
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
     */
    public function flatBranch(): array
    {
        if (!$this->isSkos || $this->isScheme()) {
            return [];
        }
        $result = [];
        $result[$this->itemId] = [
            'self' => $this->returnItem ? $this->item : $this->structure[$this->itemId] ?? null,
            'level' => 0,
        ];
        return $this->recursiveFlatBranch($this->structure[$this->itemId] ?? null, $result, 1);
    }

    /**
     * Get the list of terms or items by id from the root (top concept).
     *
     * This output is recommended for a select element form (terms).
     *
     * @uses self::list()
     * @param array $options May be:
     *   - ascendance (bool): Prepend the ascendants.
     *   - separator (string): Ascendance separator (with spaces).
     *   - indent (string): String like "– " to prepend to terms to show level.
     *   - prepend_id (bool): Prepend the id of the terms.
     *   - append_id (bool): Append the id of the terms.
     *   - max_length (int): Max size of the terms.
     */
    public function listTree(?array $options = null): array
    {
        $result = $this->flatTree();
        return $this->list($result, $options ?? []);
    }

    /**
     * Get the list of terms or items by id from this item.
     *
     * This output is recommended for a select element form (terms).
     *
     * @uses self::list()
     * @param array $options May be:
     *   - ascendance (bool): Prepend the ascendants.
     *   - separator (string): Ascendance separator (with spaces).
     *   - indent (string): String like "– " to prepend to terms to show level.
     *   - prepend_id (bool): Prepend the id of the terms.
     *   - append_id (bool): Append the id of the terms.
     *   - max_length (int): Max size of the terms.
     */
    public function listBranch(?array $options = null): array
    {
        $result = $this->flatBranch();
        return $this->list($result, $options ?? []);
    }

    /**
     * Specific output for the jQuery plugin jstree, used for Omeka navigation.
     *
     * @see https://www.jstree.com
     */
    public function jsTree(): array
    {
        if (!$this->isSkos) {
            return [];
        }

        $jsRecursiveBranch = null;

        /**
         * Recursive method to get the descendant tree of an item.
         *
         * @see self::recursiveBranch()
         *
         * @param array $itemData
         * @param array $branch Internal param for recursive process.
         * @param int $level
         */
        $jsRecursiveBranch = function (?array $itemData, array $branch = [], $level = 0) use (&$jsRecursiveBranch): array {
            if (!$itemData) {
                return [];
            }
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
                        'id' => $child['id'],
                        'text' => $child['title'],
                        'children' => $jsRecursiveBranch($child, [], $level + 1),
                        // 'icon' => null,
                        // 'state' => [
                        //     'opened' => true,
                        //     'disabled' => false,
                        //     'selected' => false,
                        // ],
                        // 'li_attr' => [],
                        // 'a_attr' => [],
                        // To be compatible with Omeka core jstree-plugins.
                        'data' => [],
                    ];
                }
            }
            return array_values($branch);
        };

        $result = [];
        foreach ($this->tops() as $itemData) {
            $result[$itemData['id']] = [
                'id' => $itemData['id'],
                'text' => $itemData['title'],
                // TODO The other branch should check if the item is not set in another branch previously.
                'children' => $jsRecursiveBranch($itemData),
            ];
        }
        return array_values($result);
    }

    /**
     * Specific output for the jQuery plugin jstree, used for Omeka navigation.
     * Output is the flat format used by jstree.
     *
     * @see https://www.jstree.com
     */
    public function jsFlatTree(): array
    {
        $tree = $this->flatTree();
        foreach ($tree as &$element) {
            $element = [
                'id' => $element['self']['id'],
                'parent' => $element['self']['top'] || empty($element['self']['parent']) ? '#' : $element['self']['parent'],
                'text' => $element['self']['title'],
                // 'icon' => null,
                // 'state' => [
                //     'opened' => true,
                //     'disabled' => false,
                //     'selected' => false,
                // ],
                // 'li_attr' => [],
                // 'a_attr' => [],
                // To be compatible with Omeka core jstree-plugins.
                'data' => [],
            ];
        }
        unset($element);
        return array_values($tree);
    }

    /**
     * Get the list of terms or items by id from item data.
     *
     * This output is recommended for a select element form (terms).
     *
     * @todo Add option group: Get tops terms as group for a grouped select. Useless.
     *
     * @param array $options Only valable for term output.
     *   - ascendance (bool): Prepend the ascendants.
     *   - separator (string): Ascendance separator (with spaces).
     *   - indent (string): String like "– " to prepend to terms to show level.
     *   - prepend_id (bool): Prepend the id of the terms.
     *   - append_id (bool): Append the id of the terms.
     *   - max_length (int): Max size of the terms.
     */
    protected function list(array $list, array $options): array
    {
        if ($this->returnItem) {
            return array_combine(array_keys($list), array_column($list, 'self'));
        }

        $options += [
            'ascendance' => false,
            'separator' => $this->separator,
            'indent' => '',
            'prepend_id' => false,
            'append_id' => false,
            'max_length' => 0,
        ];

        // Check is done with a loop, quicker than array_map().

        if ($options['ascendance']) {
            $separator = $options['separator'];

            // If the separator is included in a term (except top level), it
            // means that the template uses the full path (alternative label in
            // the recommended template), so don't add it.
            $hasSeparator = false;
            foreach ($list as $data) {
                if (mb_strpos($data['self']['title'], $separator) !== false) {
                    $hasSeparator = true;
                    break;
                }
            }
            if ($hasSeparator) {
                // Set message only when template title is skos:prefLabel.
                // TODO Check setting "thesaurus_property_descriptor" too.
                /** @var \Omeka\Api\Representation\ResourceTemplateRepresentation $templateConcept */
                $templateConcept = $this->api->read('resource_templates', ['label' => 'Thesaurus Concept'])->getContent();
                $titleProperty = $templateConcept->titleProperty();
                if ($titleProperty && $titleProperty->term() === 'skos:prefLabel') {
                    $this->logger->warn(new \Omeka\Stdlib\Message(
                        'At least one descriptor ("%1$s") contains the separator "%2$s". You must change it.', // @translate
                        $data['self']['title'], $separator
                    ));
                }
                // Else, the path is probably used as title, so no need to
                // create the tree structure here.
            } else {
                foreach ($list as &$term) {
                    if ($term['level'] && isset($this->structure[$term['self']['id']])) {
                        $ascendance = $this->ancestors($this->structure[$term['self']['id']]);
                        if (count($ascendance)) {
                            $ascendanceTitles = array_reverse(array_column($ascendance, 'title', 'id'));
                            $term['self']['title'] = implode($separator, $ascendanceTitles) . $separator . $term['self']['title'];
                        }
                    }
                }
                unset($term);
            }
        }

        if (mb_strlen($options['indent'])) {
            $indent = $options['indent'];
            foreach ($list as &$term) {
                if ($term['level']) {
                    $term['self']['title'] = str_repeat($indent, $term['level']) . ' ' . $term['self']['title'];
                }
            }
            unset($term);
        }

        if ($options['prepend_id']) {
            foreach ($list as &$term) {
                $term['self']['title'] = $term['self']['id'] . ': ' . $term['self']['title'];
            }
            unset($term);
        }

        if ($options['append_id']) {
            foreach ($list as &$term) {
                $term['self']['title'] = $term['self']['title'] . ' (' . $term['self']['id'] . ')';
            }
            unset($term);
        }

        if ($options['max_length']) {
            $maxLength = (int) $options['max_length'];
            foreach ($list as &$term) {
                $term = mb_substr($term['self']['title'], 0, $maxLength);
            }
            unset($term);
        } else {
            foreach ($list as &$term) {
                $term = $term['self']['title'];
            }
            unset($term);
        }

        return $list;
    }

    /**
     * Get the name of the current resource class.
     *
     * @param ItemRepresentation $item
     */
    protected function resourceClassName(ItemRepresentation $item): string
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
     */
    protected function resourceFromValue(ItemRepresentation $item, $term): ?ItemRepresentation
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
        return null;
    }

    /**
     * Get all linked resources of this item for a term.
     *
     * @param array $itemData
     * @param string $term
     */
    protected function resourcesFromValue(?array $itemData, $term): array
    {
        if (!$itemData) {
            return [];
        }
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
    protected function resourcesItemsFromValue(ItemRepresentation $item, $term): array
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
    protected function parent(?array $itemData): ?array
    {
        if (!$itemData) {
            return null;
        }
        $parent = $this->structure[$itemData['id']]['parent'];
        return $parent ? $this->structure[$parent] : null;
    }

    /**
     * Get the narrower items of an item.
     *
     * @param array $itemData
     */
    protected function children(?array $itemData): array
    {
        if (!$itemData) {
            return [];
        }
        $children = $this->structure[$itemData['id']]['children'] ?? [];
        return array_map(function ($v) {
            return $this->structure[$v];
        }, $children);
    }

    /**
     * Recursive method to get the top concept of an item.
     *
     * @param array $itemData
     * @param int $level
     */
    protected function ancestor(?array $itemData, $level = 0): ?array
    {
        if (!$itemData) {
            return null;
        }
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
     */
    protected function ancestors(?array $itemData, array $list = [], $level = 0): array
    {
        if (!$itemData) {
            return $list;
        }
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
    protected function listDescendants(?array $itemData, array $list = [], $level = 0): array
    {
        if (!$itemData) {
            return [];
        }
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
     */
    protected function recursiveBranch(?array $itemData, array $branch = [], $level = 0): array
    {
        if (!$itemData) {
            return [];
        }
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
     */
    protected function recursiveBranchItems(ItemRepresentation $item, array $branch = [], $level = 0): array
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
     */
    protected function recursiveFlatBranch(?array $itemData, array $branch = [], int $level = 0): array
    {
        if (!$itemData) {
            return [];
        }
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
     * Get all property ids by term.
     *
     * @return array Associative array of ids by term.
     */
    protected function getPropertyIds(): array
    {
        if (isset($this->terms['property'])) {
            return $this->terms['property'];
        }

        $connection = $this->entityManager->getConnection();
        $qb = $connection->createQueryBuilder();
        $qb
            ->select(
                'DISTINCT CONCAT(vocabulary.prefix, ":", property.local_name) AS term',
                'property.id AS id',
                // Only the two first selects are needed, but some databases
                // require "order by" or "group by" value to be in the select.
                'vocabulary.id'
            )
            ->from('property', 'property')
            ->innerJoin('property', 'vocabulary', 'vocabulary', 'property.vocabulary_id = vocabulary.id')
            ->orderBy('vocabulary.id', 'asc')
            ->addOrderBy('property.id', 'asc')
            ->addGroupBy('property.id')
        ;
        $this->terms['property'] = array_map('intval', $connection->executeQuery($qb)->fetchAllKeyValue());
        return $this->terms['property'];
    }

    /**
     * Get all class ids by term.
     *
     * @return array Associative array of ids by term.
     */
    protected function getResourceClassIds(): array
    {
        if (isset($this->terms['class'])) {
            return $this->terms['class'];
        }

        $connection = $this->entityManager->getConnection();
        $qb = $connection->createQueryBuilder();
        $qb
            ->select(
                'DISTINCT CONCAT(vocabulary.prefix, ":", resource_class.local_name) AS term',
                'resource_class.id AS id',
                // Only the two first selects are needed, but some databases
                // require "order by" or "group by" value to be in the select.
                'vocabulary.id'
            )
            ->from('resource_class', 'resource_class')
            ->innerJoin('resource_class', 'vocabulary', 'vocabulary', 'resource_class.vocabulary_id = vocabulary.id')
            ->orderBy('vocabulary.id', 'asc')
            ->addOrderBy('resource_class.id', 'asc')
            ->addGroupBy('resource_class.id')
        ;
        $this->terms['class'] = array_map('intval', $connection->executeQuery($qb)->fetchAllKeyValue());
        return $this->terms['class'];
    }

    protected function init(): self
    {
        // No base item: reset the thesaurus.
        if (empty($this->item)) {
            $this->structure = [];
            $this->tops = [];
            $this->itemId = null;
            $this->itemSet = null;
            $this->itemSetId = null;
            $this->scheme = null;
            $this->isSkos = false;
            $this->isScheme = false;
            $this->isConcept = false;
            $this->isCollection = false;
            $this->isOrderedCollection = false;
            return $this;
        }

        // The structure is already built if the current item is the item id.
        if ($this->item->id() === $this->itemId) {
            return $this;
        }

        // This is a new item.
        $this->itemId = $this->item->id();
        $this->itemSet = null;
        $this->itemSetId = null;
        $this->isScheme = null;
        $this->isConcept = null;
        $this->isCollection = null;
        $this->isOrderedCollection = null;

        // Don't rebuild the thesaurus if the current item is inside it.
        // Keep the related data null, so updated only when needed.
        if ($this->isInThesaurus()) {
            return $this;
        }

        // Rebuild the thesaurus.
        $this->structure = [];
        $this->tops = [];
        $this->scheme = null;
        $this->isSkos = null;

        return $this
            ->cacheTerms()
            ->cacheStructure();
    }

    protected function cacheTerms(): self
    {
        if (count($this->terms) === 2) {
            return $this;
        }
        // Only skos is useful for now, but speed is same with all terms.
        $this->getResourceClassIds();
        $this->getPropertyIds();
        return $this;
    }

    protected function cacheStructure(): self
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

        // Get all ids and titles via api.
        // This allows to check visibility and to get the full list of concepts.
        // The validity of top concepts is not checked.
        $titles = $this->api
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
                    // Is public is automatically managed via the api.
                ],
                [
                    'initialize' => false,
                    'returnScalar' => 'title',
                ]
            )
            ->getContent();

        // TODO This check is useless: there is at least the item, else there is an issue (item is not visible?).
        if (!count($titles)) {
            // Set the value isSkos, because it is used directly in many places.
            $this->isSkos();
            return $this;
        }

        $concepts = array_keys($titles);

        // TODO Add the root and siblings.
        $this->structure = array_fill_keys($concepts, [
            'id' => null,
            'title' => null,
            'top' => false,
            'parent' => null,
            'children' => [],
        ]);

        $qb = $this->entityManager->createQueryBuilder();
        $expr = $qb->expr();

        // Get all parents. Does not get items without parent (top).
        $qb = $this->entityManager->createQueryBuilder()
            ->select(
                'item.id',
                // There is only zero or one parent, but this is a grouped query.
                'GROUP_CONCAT(DISTINCT IDENTITY(value_list.valueResource)) AS ids'
            )
            ->from(\Omeka\Entity\Item::class, 'item')

            // This join is useless now, since the list is filtered below by the
            // list of concept ids.
            /*
            ->leftJoin(\Omeka\Entity\Value::class, 'value', \Doctrine\ORM\Query\Expr\Join::WITH, $expr->eq('value.property', ':propertyInScheme'))
            ->andWhere($expr->eq('item.resourceClass', ':concept'))
            ->andWhere($expr->eq('value.valueResource', ':scheme'))
            */

            // Use an InnerJoin: no top is needed here.
            // Furthermore, do not use where.
            ->innerJoin(
                \Omeka\Entity\Value::class,
                'value_list',
                \Doctrine\ORM\Query\Expr\Join::WITH,
                $expr->andX(
                    $expr->eq('value_list.resource', 'item.id'),
                    $expr->eq('value_list.property', ':propertyBroader')
                )
            )

            // To speed preparation of big thesaurus, limit items to ids inside
            // thesaurus. It allows to check advanced visibility too.
            // Do not use "in" in join to speed process.
            ->andWhere($expr->in('item.id', ':concepts'))
            ->andWhere($expr->in('value_list.valueResource', ':concepts'))
            ->groupBy('item.id')
            ->addOrderBy('item.id', 'ASC')
            // Use array collections is quicker and allows to pass types.
            ->setParameters(new ArrayCollection([
                // new Parameter('propertyInScheme', $this->terms['property']['skos:inScheme'], ParameterType::INTEGER),
                new Parameter('propertyBroader', $this->terms['property']['skos:broader'], ParameterType::INTEGER),
                // new Parameter('concept', $this->terms['class']['skos:Concept'], ParameterType::INTEGER),
                // new Parameter('scheme', $this->scheme->id(), ParameterType::INTEGER),
                new Parameter('concepts', $concepts, \Doctrine\DBAL\Connection::PARAM_INT_ARRAY),
            ]));

        // It is useless to check if the ids are public for visitors: the list
        // is now limited to the list of ids that is get via the api.
        /*
        if ($this->isPublic) {
            $qb
                ->innerJoin(\Omeka\Entity\Resource::class, 'resource', \Doctrine\ORM\Query\Expr\Join::WITH, $expr->eq('resource.id', 'item.id'))
                ->andWhere('resource.isPublic = 1');
        }
        */

        $parents = array_column($qb->getQuery()->getScalarResult(), 'ids', 'id');
        // There is only one parent for each concept.
        // Hack to quick process.
        // $parents = array_map('intval', $parents);
        foreach ($parents as &$parent) {
            $parent += 0;
        }
        unset($parent);

        // Get all children.
        // See previous commits for full old query.
        $qb = $this->entityManager->createQueryBuilder()
            ->select(
                'item.id',
                'GROUP_CONCAT(DISTINCT IDENTITY(value_list.valueResource) ORDER BY value_list.id) AS ids'
            )
            ->from(\Omeka\Entity\Item::class, 'item')
            ->innerJoin(
                \Omeka\Entity\Value::class,
                'value_list',
                \Doctrine\ORM\Query\Expr\Join::WITH,
                $expr->andX(
                    $expr->eq('value_list.resource', 'item.id'),
                    $expr->eq('value_list.property', ':propertyNarrower')
                )
            )
            ->andWhere($expr->in('item.id', ':concepts'))
            ->andWhere($expr->in('value_list.valueResource', ':concepts'))
            ->groupBy('item.id')
            ->addOrderBy('item.id', 'ASC')
            ->setParameters(new ArrayCollection([
                new Parameter('propertyNarrower', $this->terms['property']['skos:narrower'], ParameterType::INTEGER),
                new Parameter('concepts', $concepts, \Doctrine\DBAL\Connection::PARAM_INT_ARRAY),
            ]));

        $children = array_column($qb->getQuery()->getScalarResult(), 'ids', 'id');
        foreach ($children as &$child) {
            $v = [];
            // Hack to cast int quickly: $child = array_map('intval', explode(',', $child));
            foreach (explode(',', $child) as $c) {
                $v[] = $c + 0;
            }
            $child = $v;
        }
        unset($child);

        // Fill the full structure.
        // The check for public parent/children is done above.
        foreach ($this->structure as $id => &$value) {
            $value['id'] = $id;
            $value['title'] = $titles[$id];
            if (!array_key_exists($id, $parents)) {
                $value['top'] = true;
                $this->tops[$id] = $value;
            }
        }
        foreach ($parents as $id => $parent) {
            $this->structure[$id]['parent'] = $parent;
        }
        foreach ($children as $id => $childs) {
            $this->structure[$id]['children'] = $childs;
        }

        // Set the value isSkos, because it is used directly in many places.
        $this->isSkos();

        return $this;
    }

    /**
     * Get an item or a list of items from id, data, or associative list of ids.
     *
     * Items are returned only if the deprecated option returnItem is set, so
     * most of the time, returns data directly.
     *
     * This method is the same than calling api with a list of ids, but it is
     * quicker and without events.
     *
     * @return array|null|ItemRepresentation
     */
    protected function returnFromData(?array $data)
    {
        if (!$this->returnItem || is_null($data) || !count($data)) {
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
            ->setParameter('ids', array_keys($data), \Doctrine\DBAL\Connection::PARAM_INT_ARRAY)
        ;
        $result = $qb->getQuery()->getResult();
        foreach ($result as &$itemData) {
            $itemData = $this->adapter->getRepresentation($itemData);
        }

        return $isSingle
            ? reset($result)
            : $result;
    }
}
