<?php declare(strict_types=1);

namespace Thesaurus\View\Helper;

use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Representation\ItemRepresentation;
use Thesaurus\Mvc\Controller\Plugin\Thesaurus as ThesaurusPlugin;

/**
 * @todo Implement a tree iterator.
 */
class Thesaurus extends AbstractHelper
{
    /**
     * @param ThesaurusPlugin
     */
    protected $thesaurus;

    /**
     * @param ItemRepresentation
     */
    protected $item;

    /**
     * @param ThesaurusPlugin $thesaurus
     */
    public function __construct(ThesaurusPlugin $thesaurus)
    {
        $this->thesaurus = $thesaurus;
    }

    /**
     * Get the thesaurus helper.
     */
    public function __invoke(ItemRepresentation $item): self
    {
        $this->item = $item;
        $thesaurusPlugin = $this->thesaurus;
        $thesaurusPlugin($item);
        return $this;
    }

    /**
     * If true, return item representations, else  a small array of term data.
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
        $this->thesaurus->setReturnItem();
        return $this;
    }

    /**
     * Helper to get the item representation from item data.
     *
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::itemFromData()
     */
    public function itemFromData(array $itemData = null): ?ItemRepresentation
    {
        return $this->thesaurus->itemFromData($itemData);
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
    public function ascendants(): array
    {
        return $this->thesaurus->ascendants();
    }

    /**
     * Get the list of ascendants of this item, from self first to top concept.
     *
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::ascendantsOrSelf()
     * @return ItemRepresentation[]|array
     */
    public function ascendantsOrSelf(): array
    {
        return $this->thesaurus->ascendantsOrSelf();
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
     * Get the hierarchy of this item from the root (top concepts).
     *
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::tree()
     */
    public function tree(): array
    {
        return $this->thesaurus->tree();
    }

    /**
     * Get the hierarchy branch of this item, self included.
     *
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::branch()
     */
    public function branch(): array
    {
        return $this->thesaurus->branch();
    }

    /**
     * Get the flat hierarchy of this item from the root (top concepts).
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
     * @param array $options May be: Indent, prepend_id.
     */
    public function listTree(array $options = null): array
    {
        return $this->thesaurus->listTree($options);
    }

    /**
     * Get the list of terms or items by id from this item.
     *
     * This output is recommended for a select element form (terms).
     *
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::listBranch()
     * @param array $options May be: Indent, prepend_id.
     */
    public function listBranch(array $options = null): array
    {
        return $this->thesaurus->listBranch($options);
    }

    /**
     * Display part of a thesaurus.
     *
     * @param string|array|ItemRepresentation $typeOrData Type may be "root" or
     * "broader" (single), "tops", "narrowers", "relateds", "siblings",
     * "ascendants", or "descendants" (list), or "tree" or "branch" (tree), or
     * "flatTree" or "flatBranch" (flat tree).
     * @param array $options Options for the partial. Managed default are
     * "title", "link", "link_append_concept", "term", "hideIfEmpty", "class",
     * "expanded", "template", "returnItem". Deprecated option : "partial"
     * (renamed "template").
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
            'item' => $this->item,
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
