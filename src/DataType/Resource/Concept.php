<?php declare(strict_types=1);

namespace Thesaurus\DataType\Resource;

use Laminas\View\Renderer\PhpRenderer;
use Omeka\DataType\Resource\AbstractResource;
use Omeka\DataType\ValueAnnotatingInterface;
use Thesaurus\Entity\Concept as ConceptEntity;

/**
 * Generic data type to fill value with any thesaurus concept, for any scheme.
 *
 * The per-scheme data type "thesaurus:{schemeId}" delegates its hydrate to this
 * one, so a concept is stored as a linked resource of its own type.
 */
class Concept extends AbstractResource implements ValueAnnotatingInterface
{
    public function getName()
    {
        return 'resource:concept';
    }

    public function getLabel()
    {
        return 'Concept'; // @translate
    }

    public function getValidValueResources()
    {
        return [ConceptEntity::class];
    }

    public function prepareForm(PhpRenderer $view)
    {
        $view->headScript()->appendFile($view->assetUrl('js/concept-resource-form.js', 'Thesaurus'), 'text/javascript', ['defer' => 'defer']);
    }

    /**
     * The core renders the button of selection only for its own resources.
     */
    public function form(PhpRenderer $view)
    {
        return $view->partial('common/data-type/resource-concept');
    }

    public function valueAnnotationPrepareForm(PhpRenderer $view): void
    {
    }

    public function valueAnnotationForm(PhpRenderer $view)
    {
        return $view->partial('common/data-type/value-annotation-resource', [
            'dataTypeLabel' => $view->translate('Concepts'), // @translate
            'dataTypeSingle' => 'concept',
            'dataTypePlural' => 'concepts',
        ]);
    }
}
