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
     *
     * @param ItemRepresentation $item
     * @return mixed.
     */
    public function __invoke(ItemRepresentation $item)
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
     * @deprecated Use itemFromData() instead, in particular for big thesaurus.
     * @param bool $returnItem
     * @return \Thesaurus\Mvc\Controller\Plugin\Thesaurus
     */
    public function setReturnItem($returnItem = false)
    {
        $this->thesaurus->setReturnItem();
        return $this;
    }

    /**
     * Helper to get the item representation from item data.
     *
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::itemFromData()
     * @param array $itemData
     * @return ItemRepresentation|null
     */
    public function itemFromData(array $itemData = null)
    {
        return $this->thesaurus->itemFromData($itemData);
    }

    /**
     * This item is a skos item if it has at a skos class or a skos property.
     *
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::isSkos()
     * @return bool
     */
    public function isSkos()
    {
        return $this->thesaurus->isSkos();
    }

    /**
     * This item is a scheme if it has the class ConceptScheme or a top concept.
     *
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::isScheme()
     * @return bool
     */
    public function isScheme()
    {
        return $this->thesaurus->isScheme();
    }

    /**
     * This item is a concept if it has the class Concept or a required property
     * of a concept (skos:broader, skos:narrower or skos:topConceptOf).
     *
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::isConcept()
     * @return bool
     */
    public function isConcept()
    {
        return $this->thesaurus->isConcept();
    }

    /**
     * This item is a collection if it has class Collection or OrderedCollection
     * or properties skos:member or skos:memberList.
     *
     * Note: an OrderedCollection is a collection.
     *
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::isCollection()
     * @param bool $strict
     * @return bool
     */
    public function isCollection($strict = false)
    {
        return $this->thesaurus->isCollection($strict);
    }

    /**
     * This item is an ordered collection if it has the class OrderedCollection,
     * or a property skos:memberList.
     *
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::isOrderedCollection()
     * @return bool
     */
    public function isOrderedCollection()
    {
        return $this->thesaurus->isOrderedCollection();
    }

    /**
     * Get the scheme of this item.
     *
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::scheme()
     * @return ItemRepresentation|null
     */
    public function scheme()
    {
        return $this->thesaurus->scheme();
    }

    /**
     * Get the top concepts of the scheme.
     *
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::tops()
     * @return ItemRepresentation[]
     */
    public function tops()
    {
        return $this->thesaurus->tops();
    }

    /**
     * Check if a concept is a root (top concept).
     *
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::isRoot()
     * @return bool
     */
    public function isRoot()
    {
        return $this->thesaurus->isRoot();
    }

    /**
     * Get the root concept of this item, that may be itself.
     *
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::root()
     * @return ItemRepresentation
     */
    public function root()
    {
        return $this->thesaurus->root();
    }

    /**
     * Get the broader concept of this item.
     *
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::broader()
     * @return ItemRepresentation|null
     */
    public function broader()
    {
        return $this->thesaurus->broader();
    }

    /**
     * Get the narrower concepts of this item.
     *
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::narrowers()
     * @return ItemRepresentation[]
     */
    public function narrowers()
    {
        return $this->thesaurus->narrowers();
    }

    /**
     * Get the related concepts of this item.
     *
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::relateds()
     * @return ItemRepresentation[]
     */
    public function relateds()
    {
        return $this->thesaurus->relateds();
    }

    /**
     * Get the related concepts of this item, with self.
     *
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::relatedsOrSelf()
     * @return ItemRepresentation[]
     */
    public function relatedsOrSelf()
    {
        return $this->thesaurus->relatedsOrSelf();
    }

    /**
     * Get the sibling concepts of this item (self not included).
     *
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::siblings()
     * @return ItemRepresentation[]
     */
    public function siblings()
    {
        return $this->thesaurus->siblings();
    }

    /**
     * Get the sibling concepts of this item (self included).
     *
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::siblingsOrSelf()
     * @return ItemRepresentation[]
     */
    public function siblingsOrSelf()
    {
        return $this->thesaurus->siblingsOrSelf();
    }

    /**
     * Get the list of ascendants of this item, from closest to top concept.
     *
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::ascendants()
     * @return ItemRepresentation[]
     */
    public function ascendants()
    {
        return $this->thesaurus->ascendants();
    }

    /**
     * Get the list of ascendants of this item, from self first to top concept.
     *
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::ascendantsOrSelf()
     * @return ItemRepresentation[]
     */
    public function ascendantsOrSelf()
    {
        return $this->thesaurus->ascendantsOrSelf();
    }

    /**
     * Get the list of descendants of this item.
     *
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::descendants()
     * @return ItemRepresentation[]
     */
    public function descendants()
    {
        return $this->thesaurus->descendants();
    }

    /**
     * Get the list of descendants of this item, with self last.
     *
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::descendantsOrSelf()
     * @return ItemRepresentation[]
     */
    public function descendantsOrSelf()
    {
        return $this->thesaurus->descendantsOrSelf();
    }

    /**
     * Get the hierarchy of this item from the root (top concepts).
     *
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::tree()
     * @return array
     */
    public function tree()
    {
        return $this->thesaurus->tree();
    }

    /**
     * Get the hierarchy branch of this item, self included.
     *
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::branch()
     * @return array
     */
    public function branch()
    {
        return $this->thesaurus->branch();
    }

    /**
     * Get the flat hierarchy of this item from the root (top concepts).
     *
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::flatTree()
     * @return array
     */
    public function flatTree()
    {
        return $this->thesaurus->flatTree();
    }

    /**
     * Get the flat hierarchy branch of this item, self included.
     *
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::flatBranch()
     * @return array
     */
    public function flatBranch()
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
     * @return array
     */
    public function listTree(array $options = null)
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
     * @return array
     */
    public function listBranch(array $options = null)
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
     * "title", "link", "term", "hideIfEmpty", "class", "expanded", "partial",
     * "returnItem".
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
                'relateds' => 'list',
                'siblings' => 'list',
                'siblingsOrSelf' => 'list',
                'ascendants' => 'list',
                'descendants' => 'list',
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

        $partial = empty($options['partial'])
            ? 'common/thesaurus-' . $partial
            : $options['partial'];
        unset($options['partial']);

        $options += [
            'title' => '',
            'link' => 'both',
            'term' => 'dcterms:subject',
            'hideIfEmpty' => false,
            'class' => '',
            'expanded' => 0,
            'returnItem' => false,
        ];

        return $this->getView()->partial($partial, [
            'site' => $this->currentSite(),
            'item' => $this->item,
            'type' => $type,
            'data' => $data,
            'options' => $options,
        ]);
    }

    /**
     * @return \Omeka\Api\Representation\SiteRepresentation
     */
    protected function currentSite()
    {
        $view = $this->getView();
        return isset($view->site)
            ? $view->site
            : $view->getHelperPluginManager()->get('Laminas\View\Helper\ViewModel')->getRoot()->getVariable('site');
    }
}
