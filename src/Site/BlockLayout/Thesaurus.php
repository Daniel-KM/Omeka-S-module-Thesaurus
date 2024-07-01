<?php declare(strict_types=1);

namespace Thesaurus\Site\BlockLayout;

use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\SitePageBlockRepresentation;
use Omeka\Api\Representation\SitePageRepresentation;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Site\BlockLayout\AbstractBlockLayout;
use Omeka\Site\BlockLayout\TemplateableBlockLayoutInterface;

class Thesaurus extends AbstractBlockLayout implements TemplateableBlockLayoutInterface
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

        $data = $block ? ($block->data() ?? []) + $defaultSettings : $defaultSettings;

        $dataForm = [];
        foreach ($data as $key => $value) {
            $dataForm['o:block[__blockIndex__][o:data][' . $key . ']'] = $value;
        }

        $fieldset = $formElementManager->get($blockFieldset);
        $fieldset->populateValues($dataForm);

        return $view->formCollection($fieldset);
    }

    public function render(PhpRenderer $view, SitePageBlockRepresentation $block, $templateViewScript = self::PARTIAL_NAME)
    {
        $itemId = (int) $block->dataValue('item');
        try {
            $item = $itemId ? $view->api()->read('items', ['id' => $itemId])->getContent() : null;
        } catch (\Exception $e) {
            if ($block->dataValue('hideIfEmpty')) {
                return '';
            }
            $item = null;
        }

        $vars = [
            'block' => $block,
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

        return $view->partial($templateViewScript, $vars);
    }
}
