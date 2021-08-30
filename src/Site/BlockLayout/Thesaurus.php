<?php declare(strict_types=1);
namespace Thesaurus\Site\BlockLayout;

use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\SitePageBlockRepresentation;
use Omeka\Api\Representation\SitePageRepresentation;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Site\BlockLayout\AbstractBlockLayout;

class Thesaurus extends AbstractBlockLayout
{
    /**
     * The default partial view script.
     */
    const PARTIAL_NAME = 'common/block-layout/thesaurus';

    public function getLabel()
    {
        return 'Thesaurus'; // @translate
    }

    public function form(
        PhpRenderer $view,
        SiteRepresentation $site,
        SitePageRepresentation $page = null,
        SitePageBlockRepresentation $block = null
    ) {
        // Factory is not used to make rendering simpler.
        $services = $site->getServiceLocator();
        $formElementManager = $services->get('FormElementManager');
        $defaultSettings = $services->get('Config')['thesaurus']['block_settings']['thesaurus'];
        $blockFieldset = \Thesaurus\Form\ThesaurusFieldset::class;

        $data = $block ? $block->data() + $defaultSettings : $defaultSettings;

        $dataForm = [];
        foreach ($data as $key => $value) {
            $dataForm['o:block[__blockIndex__][o:data][' . $key . ']'] = $value;
        }

        $fieldset = $formElementManager->get($blockFieldset);
        $fieldset->populateValues($dataForm);

        return $view->formCollection($fieldset);
    }

    public function render(PhpRenderer $view, SitePageBlockRepresentation $block)
    {
        $item = $view->api()->searchOne('items', ['id' => $block->dataValue('item')])->getContent();
        if (empty($item) && $block->dataValue('hideIfEmpty')) {
            return '';
        }

        $vars = [
            'block' => $block,
            'heading' => $block->dataValue('heading', ''),
            'item' => $item,
            'type' => $block->dataValue('type', 'tree'),
            'options' => [
                'link' => $block->dataValue('link', 'both'),
                'term' => $block->dataValue('term', 'dcterms:subject'),
                'hideIfEmpty' => (bool) $block->dataValue('hideIfEmpty', false),
                'expanded' => (int) $block->dataValue('expanded', 0),
                'title' => '',
            ],
        ];

        $template = $block->dataValue('template', self::PARTIAL_NAME);
        unset($vars['template']);

        return $template !== self::PARTIAL_NAME && $view->resolver($template)
            ? $view->partial($template, $vars)
            : $view->partial(self::PARTIAL_NAME, $vars);
    }
}
