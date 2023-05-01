<?php declare(strict_types=1);

namespace Thesaurus\View\Helper;

use Laminas\Form\FormElementManager;
use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\ItemSetRepresentation;
use Thesaurus\Mvc\Controller\Plugin\Thesaurus as ThesaurusPlugin;

/**
 * @todo Implement a tree iterator.
 */
class Thesaurus extends AbstractHelper
{
    /**
     * @var \Thesaurus\Mvc\Controller\Plugin\Thesaurus
     */
    protected $thesaurus;

    /**
     * @var FormElementManager
     */
    protected $formElementManager;

    /**
     * @fixme The same thesaurus is shared between all helpers (even if it can be reinit with another item).
     */
    public function __construct(ThesaurusPlugin $thesaurus, FormElementManager $formElementManager)
    {
        $this->thesaurus = $thesaurus;
        $this->formElementManager = $formElementManager;
    }

    /**
     * Get the thesaurus helper.
     *
     * @param AbstractResourceEntityRepresentation|int|null $itemOrItemSetOrId
     *   The item should be a scheme or a concept. If item set, it should be a
     *   skos collection or a skos ordered collection that contains a scheme.
     *   The thesaurus will be init with this concept or scheme. It will be used
     *   by default in other methods until another method modify it.
     */
    public function __invoke($itemOrItemSetOrId = null): self
    {
        $this->thesaurus->__invoke($itemOrItemSetOrId);
        return $this;
    }

    /**
     * If true, return item representations, else a small array of term data.
     *
     * The method scheme() always returns an item.
     * It is not recommended to return items with big thesaurus.
     *
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::setReturnItem()
     *
     * @deprecated Use itemFromData() instead, in particular for big thesaurus.
     */
    public function setReturnItem(bool $returnItem = false): self
    {
        $this->thesaurus->setReturnItem($returnItem);
        return $this;
    }

    /**
     * Set a base item.
     *
     * If the item does not belong to the current thesaurus, the thesaurus is
     * reinitialized. If the item is empty, the thesaurus is reset.
     *
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::setItem()
     */
    public function setItem(?ItemRepresentation $item): self
    {
        $this->thesaurus->setItem($item);
        return $this;
    }

    /**
     * Return the item used to build the thesaurus or the last item used.
     *
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::getItem()
     */
    public function getItem(): ?ItemRepresentation
    {
        return $this->thesaurus->getItem();
    }

    /**
     * Return the item set associated to this thesaurus.
     *
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::getItemSet()
     */
    public function getItemSet(): ?ItemSetRepresentation
    {
        return $this->thesaurus->getItemSet();
    }

    /**
     * Check if the specified item is in the thesaurus.
     *
     * @param ItemRepresentation|int $itemOrId
     *
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::isInThesaurus()
     */
    public function isInThesaurus($itemOrId = null): bool
    {
        return $this->thesaurus->isInThesaurus($itemOrId);
    }

    /**
     * Get the item representation from item data or id, or get current item.
     *
     * @param array|int|null $itemData
     * @return ItemRepresentation Return the current item when empty
     *
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::itemFromData()
     */
    public function itemFromData($itemData = null): ?ItemRepresentation
    {
        return $this->thesaurus->itemFromData($itemData);
    }

    /**
     * Return the data for the item used to build the thesaurus or any item.
     *
     * @param ItemRepresentation|int $item
     *
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::itemToData()
     */
    public function itemToData($itemOrId = null): ?array
    {
        return $this->thesaurus->itemToData($itemOrId);
    }

    /**
     * This item is a skos item if it has at a skos class or a skos property.
     *
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::isSkos()
     */
    public function isSkos(): bool
    {
        return $this->thesaurus->isSkos();
    }

    /**
     * This item is a scheme if it has the class ConceptScheme or a top concept.
     *
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::isScheme()
     */
    public function isScheme(): bool
    {
        return $this->thesaurus->isScheme();
    }

    /**
     * This item is a concept if it has the class Concept or a required property
     * of a concept (skos:broader, skos:narrower or skos:topConceptOf).
     *
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::isConcept()
     */
    public function isConcept(): bool
    {
        return $this->thesaurus->isConcept();
    }

    /**
     * Check if a concept is a top concept.
     *
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::isTop()
     */
    public function isTop(): bool
    {
        return $this->thesaurus->isTop();
    }

    /**
     * Check if a concept is a root (top concept).
     *
     * @deprecated Use isTop() instead. Root is more like the scheme.
     * @see self:isTop()
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::isTop()
     */
    public function isRoot(): bool
    {
        return $this->thesaurus->isTop();
    }

    /**
     * This item is a collection if it has class Collection or OrderedCollection
     * or properties skos:member or skos:memberList.
     *
     * Note: an OrderedCollection is a collection.
     *
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::isCollection()
     */
    public function isCollection(bool $strict = false): bool
    {
        return $this->thesaurus->isCollection($strict);
    }

    /**
     * This item is an ordered collection if it has the class OrderedCollection,
     * or a property skos:memberList.
     *
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::isOrderedCollection()
     */
    public function isOrderedCollection(): bool
    {
        return $this->thesaurus->isOrderedCollection();
    }

    /**
     * Get the current item as array (may be empty).
     *
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::selfItem()
     */
    public function selfItem(): array
    {
        return $this->thesaurus->selfItem();
    }

    /**
     * Get the scheme of this item.
     *
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::scheme()
     */
    public function scheme(): ?ItemRepresentation
    {
        return $this->thesaurus->scheme();
    }

    /**
     * Get the top concepts of the scheme.
     *
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::tops()
     * @return ItemRepresentation[]|array
     */
    public function tops(): array
    {
        return $this->thesaurus->tops();
    }

    /**
     * Get the top concept of this item, that may be itself.
     *
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::top()
     * @return ItemRepresentation|array|null
     */
    public function top()
    {
        return $this->thesaurus->top();
    }

    /**
     * Get the root concept of this item, that may be itself.
     *
     * @deprecated Use self::top() instead. Root is more like the scheme.
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::root()
     * @return ItemRepresentation|array|null
     */
    public function root()
    {
        return $this->thesaurus->top();
    }

    /**
     * Get the broader concept of this item.
     *
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::broader()
     * @return ItemRepresentation|array|null
     */
    public function broader()
    {
        return $this->thesaurus->broader();
    }

    /**
     * Get the narrower concepts of this item.
     *
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::narrowers()
     * @return ItemRepresentation[]|array
     */
    public function narrowers(): array
    {
        return $this->thesaurus->narrowers();
    }

    /**
     * Get the list of narrower concepts of this item, with self first.
     *
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::descendantsOrSelf()
     * @return ItemRepresentation[]|array
     */
    public function narrowersOrSelf(): array
    {
        return $this->thesaurus->narrowersOrSelf();
    }

    /**
     * Get the related concepts of this item.
     *
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::relateds()
     * @return ItemRepresentation[]|array
     */
    public function relateds(): array
    {
        return $this->thesaurus->relateds();
    }

    /**
     * Get the related concepts of this item, with self.
     *
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::relatedsOrSelf()
     * @return ItemRepresentation[]|array
     */
    public function relatedsOrSelf(): array
    {
        return $this->thesaurus->relatedsOrSelf();
    }

    /**
     * Get the sibling concepts of this item (self not included).
     *
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::siblings()
     * @return ItemRepresentation[]|array
     */
    public function siblings(): array
    {
        return $this->thesaurus->siblings();
    }

    /**
     * Get the sibling concepts of this item (self included).
     *
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::siblingsOrSelf()
     * @return ItemRepresentation[]|array
     */
    public function siblingsOrSelf(): array
    {
        return $this->thesaurus->siblingsOrSelf();
    }

    /**
     * Get the list of ascendants of this item, from closest to top concept.
     *
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::ascendants()
     * @return ItemRepresentation[]|array
     */
    public function ascendants(bool $fromTop = false): array
    {
        return $this->thesaurus->ascendants($fromTop);
    }

    /**
     * Get the list of ascendants of this item, from self first to top concept.
     *
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::ascendantsOrSelf()
     * @return ItemRepresentation[]|array
     */
    public function ascendantsOrSelf(bool $fromTop = false): array
    {
        return $this->thesaurus->ascendantsOrSelf($fromTop);
    }

    /**
     * Get the list of descendants of this item.
     *
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::descendants()
     * @return ItemRepresentation[]|array
     */
    public function descendants(): array
    {
        return $this->thesaurus->descendants();
    }

    /**
     * Get the list of descendants of this item, with self first.
     *
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::descendantsOrSelf()
     * @return ItemRepresentation[]|array
     */
    public function descendantsOrSelf(): array
    {
        return $this->thesaurus->descendantsOrSelf();
    }

    /**
     * Get the hierarchy of this item from the top concepts.
     *
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::tree()
     */
    public function tree(): array
    {
        return $this->thesaurus->tree();
    }

    /**
     * Get the hierarchy branch of this item from top, self included.
     *
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::branch()
     */
    public function branch(): array
    {
        return $this->thesaurus->branch();
    }

    /**
     * Get the hierarchy branch of this item without top concept, self included,
     * except if it is the top.
     *
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::branchNoTop()
     */
    public function branchNoTop(): array
    {
        return $this->thesaurus->branchNoTop();
    }

    /**
     * Get the hierarchy branch from this item, so self and descendants as tree.
     *
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::branchFromItem()
     */
    public function branchFromItem(): array
    {
        return $this->thesaurus->branchFromItem();
    }

    /**
     * Get the hierarchy branch below this item, so descendants as a tree.
     *
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::branchFromItem()
     */
    public function branchBelowItem(): array
    {
        return $this->thesaurus->branchBelowItem();
    }

    /**
     * Get the flat hierarchy of this item from the top concepts.
     *
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::flatTree()
     */
    public function flatTree(): array
    {
        return $this->thesaurus->flatTree();
    }

    /**
     * Get the flat hierarchy branch of this item, self included.
     *
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::flatBranch()
     */
    public function flatBranch(): array
    {
        return $this->thesaurus->flatBranch();
    }

    /**
     * Get the list of terms or items by id from the root (top concept).
     *
     * This output is recommended for a select element form (terms).
     *
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::listTree()
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
        return $this->thesaurus->listTree($options);
    }

    /**
     * Get the list of terms or items by id from this item.
     *
     * This output is recommended for a select element form (terms).
     *
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::listBranch()
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
        return $this->thesaurus->listBranch($options);
    }

    /**
     * Specific output for the jQuery plugin jstree, used for Omeka navigation.
     *
     * @see https://www.jstree.com
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::jsTree()
     */
    public function jsTree()
    {
        return $this->thesaurus->jsTree();
    }

    /**
     * Specific output for the jQuery plugin jstree, used for Omeka navigation.
     * Output is the flat format used by jstree.
     *
     * @see https://www.jstree.com
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::jsFlatTree()
     */
    public function jsFlatTree()
    {
        return $this->thesaurus->jsFlatTree();
    }

    /**
     * Get a form element Select from a list tree or a list branch.
     * @param ?array $options Options from listTree and listBranch can be passed
     *   and the ones for Select like `empty_option` or `prepend_value_options`.
     * @see \Thesaurus\Form\Element\ThesaurusSelect::getValueOptions()
     */
    public function asSelect(?array $options = null): ?\Thesaurus\Form\Element\ThesaurusSelect
    {
        if (is_null($options)) {
            $options = [];
        }
        /** @var \Thesaurus\Form\Element\ThesaurusSelect $select */
        $select = $this->formElementManager->get(\Thesaurus\Form\Element\ThesaurusSelect::class);
        return $select
            ->setOptions($options)
            ->setThesaurusTerm($this->thesaurus->getItem());
    }

    /**
     * Display part of a thesaurus.
     *
     * @param string|array|ItemRepresentation $typeOrData Type may be:
     * - For output as single item or data:
     *   - root
     *   - broader
     * - For output as list of items or data:
     *   - tops
     *   - narrowers
     *   - relateds
     *   - siblings
     *   - ascendants
     *   - descendants
     * - For output as a tree of item or data:
     *   - tree
     *   - branch
     *   - branchNoTop
     *   - branchFromItem
     *   - branchBelowItem
     * - For output as a flat tree of item or data:
     *   - flatTree
     *   - flatBranch
     * If an item or a data or a list of items or data is passed, the type is
     * automatically defined.
     * @param array $options Options for the partial. Managed default are:
     * - title
     * - link
     * - link_append_concept
     * - term
     * - hideIfEmpty
     * - class
     * - expanded
     * - template
     * - returnItem (bool): return data (default) or item. It is recommended to
     *   avoid to return items with a big thesaurus.
     * - partial (deprecated: renamed "template" above)
     * @return string
     */
    public function display($typeOrData, array $options = [])
    {
        $this->thesaurus->setReturnItem(!empty($options['returnItem']));

        $type = $data = $typeOrData;
        if (is_string($typeOrData)) {
            $partialTypes = [
                'root' => 'single',
                'broader' => 'single',
                'tops' => 'list',
                'narrowers' => 'list',
                'narrowersOrSelf' => 'list',
                'relateds' => 'list',
                'relatedsOrSelf' => 'list',
                'siblings' => 'list',
                'siblingsOrSelf' => 'list',
                'ascendants' => 'list',
                'ascendantsOrSelf' => 'list',
                'descendants' => 'list',
                'descendantsOrSelf' => 'list',
                'tree' => 'tree',
                'branch' => 'tree',
                'branchNoTop' => 'tree',
                'branchFromItem' => 'tree',
                'branchBelowItem' => 'tree',
                'flatTree' => 'flat',
                'flatBranch' => 'flat',
            ];
            if (isset($partialTypes[$type])) {
                $data = $this->{$type}();
                $partial = $partialTypes[$type];
            } else {
                return '';
            }
        } else {
            $type = 'custom';
            if (is_array($data)) {
                if (is_array(reset($data))) {
                    $first = reset($data);
                    $partial = isset($first['level']) ? 'flat' : 'tree';
                } else {
                    $partial = 'list';
                }
            } else {
                $partial = 'single';
            }
        }

        $view = $this->getView();
        $template = $options['template'] ?? $options['partial'] ?? null;
        if (!$template || !$view->resolver($template)) {
            $template = 'common/thesaurus-' . $partial;
        }
        unset($options['template'], $options['partial']);

        $options += [
            'title' => '',
            'link' => 'both',
            'link_append_concept' => false,
            'term' => 'dcterms:subject',
            'hideIfEmpty' => false,
            'class' => '',
            'expanded' => 0,
            'returnItem' => false,
        ];

        return $view->partial($template, [
            'site' => $this->currentSite(),
            'item' => $this->thesaurus->getItem(),
            'type' => $type,
            'data' => $data,
            'options' => $options,
        ]);
    }

    /**
     * Get the current site from the view.
     */
    protected function currentSite(): ?\Omeka\Api\Representation\SiteRepresentation
    {
        return $this->view->site ?? $this->view->site = $this->view
            ->getHelperPluginManager()
            ->get('Laminas\View\Helper\ViewModel')
            ->getRoot()
            ->getVariable('site');
    }
}
