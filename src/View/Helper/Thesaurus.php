<?php
namespace Thesaurus\View\Helper;

use Omeka\Api\Representation\ItemRepresentation;
use Thesaurus\Mvc\Controller\Plugin\Thesaurus as ThesaurusPlugin;
use Zend\View\Helper\AbstractHelper;

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
        $thesaurus = $this->thesaurus;
        $thesaurus($item);
        return $this;
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
     * Get the root concept of this item.
     *
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::root()
     * @return ItemRepresentation|null
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
     * Get the hierarchy of this item from the root (top concept).
     *
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::tree()
     * @return ItemRepresentation[]
     */
    public function tree()
    {
        return $this->thesaurus->tree();
    }

    /**
     * Get the hierarchy branch of this item, self included.
     *
     * @uses \Thesaurus\Mvc\Controller\Plugin\Thesaurus::tree()
     * @return ItemRepresentation[]
     */
    public function branch()
    {
        return $this->thesaurus->branch();
    }

    /**
     * Display part of a thesaurus.
     *
     * @param string|array|ItemRepresentation $typeOrData Type may be "root" or
     * "broader" (single), "tops", "narrowers", "relateds", "siblings",
     * "ascendants", or "descendants" (list), or "tree" or "branch" (tree).
     * @param array $options Options for the partial. Managed default are
     * "title", "hideIfEmpty", "class", "expanded", "partial".
     * @return string
     */
    public function display($typeOrData, array $options = [])
    {
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
                $partial = is_array(reset($data)) ? 'tree' : 'list';
            } else {
                $partial = 'single';
            }
        }

        $partial = empty($options['partial'])
            ? 'common/thesaurus-' . $partial
            : $options['partial'];
        unset($options['partial']);

        $options += ['title' => '', 'hideIfEmpty' => false, 'class' => '', 'expanded' => 0];

        return $this->getView()->partial($partial, [
            'item' => $this->item,
            'type' => $type,
            'data' => $data,
            'options' => $options,
        ]);
    }
}
