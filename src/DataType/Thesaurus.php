<?php declare(strict_types=1);

namespace Thesaurus\DataType;

use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Adapter\AbstractEntityAdapter;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\ValueRepresentation;
use Omeka\DataType\DataTypeInterface;
use Omeka\DataType\ValueAnnotatingInterface;
use Omeka\Entity\Value;
use Thesaurus\Stdlib\Thesaurus as ThesaurusStdlib;

/**
 * Data type to fill a value with a concept of a thesaurus, without the need of
 * a custom vocab or an item set.
 *
 * The value is stored as a linked resource (the concept, a dedicated resource
 * type), so it is delegated to the generic data type "resource".
 */
class Thesaurus implements DataTypeInterface, ValueAnnotatingInterface
{
    /**
     * @var \Omeka\Api\Representation\ItemRepresentation
     */
    protected $scheme;

    /**
     * @var \Thesaurus\Stdlib\Thesaurus
     */
    protected $thesaurus;

    /**
     * Options passed to Thesaurus::listTree() to build the select options.
     *
     * @var array
     */
    protected $selectOptions;

    public function __construct(ItemRepresentation $scheme, ThesaurusStdlib $thesaurus, array $selectOptions = [])
    {
        $this->scheme = $scheme;
        $this->thesaurus = $thesaurus;
        $this->selectOptions = $selectOptions;
    }

    public function getName()
    {
        return 'thesaurus:' . $this->scheme->id();
    }

    public function getOptgroupLabel()
    {
        return 'Thesaurus'; // @translate
    }

    public function getLabel()
    {
        return $this->scheme->displayTitle();
    }

    public function prepareForm(PhpRenderer $view): void
    {
        // jstree (bundled in the core) powers the browse dialog of the widget,
        // that follows the standard dialog of the module Common.
        $view->headLink()
            ->appendStylesheet($view->assetUrl('css/jstree.css', 'Omeka'))
            ->appendStylesheet($view->assetUrl('css/common-dialog.css', 'Common'))
            ->appendStylesheet($view->assetUrl('css/thesaurus-selector.css', 'Thesaurus'));
        $view->headScript()
            ->appendFile($view->assetUrl('vendor/jstree/jstree.min.js', 'Omeka'))
            ->appendFile($view->assetUrl('js/thesaurus-selector.js', 'Thesaurus'));
    }

    public function form(PhpRenderer $view)
    {
        return $view->partial('common/data-type/thesaurus', [
            'schemeId' => $this->scheme->id(),
        ]);
    }

    public function isValid(array $valueObject)
    {
        return isset($valueObject['value_resource_id'])
            && is_numeric($valueObject['value_resource_id']);
    }

    public function hydrate(array $valueObject, Value $value, AbstractEntityAdapter $adapter): void
    {
        $adapter->getServiceLocator()
            ->get('Omeka\DataTypeManager')
            ->get('resource')
            ->hydrate($valueObject, $value, $adapter);
    }

    public function render(PhpRenderer $view, ValueRepresentation $value, $options = [])
    {
        $valueResource = $value->valueResource();
        if ($valueResource) {
            return $valueResource->linkPretty('square', null, null, null, $options['lang'] ?? null);
        }
        return nl2br($view->escapeHtml((string) $value->value()));
    }

    public function getJsonLd(ValueRepresentation $value)
    {
        $valueResource = $value->valueResource();
        if ($valueResource) {
            return $valueResource->valueRepresentation();
        }
        return ['@value' => (string) $value->value()];
    }

    public function getFulltextText(PhpRenderer $view, ValueRepresentation $value)
    {
        $valueResource = $value->valueResource();
        return $valueResource ? $valueResource->displayTitle() : (string) $value->value();
    }

    public function toString(ValueRepresentation $value)
    {
        $valueResource = $value->valueResource();
        return $valueResource ? $valueResource->url(null, true) : (string) $value->value();
    }

    public function valueAnnotationPrepareForm(PhpRenderer $view): void
    {
    }

    public function valueAnnotationForm(PhpRenderer $view)
    {
        return $this->form($view);
    }
}
