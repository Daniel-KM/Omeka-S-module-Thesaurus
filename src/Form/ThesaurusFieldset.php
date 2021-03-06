<?php declare(strict_types=1);
namespace Thesaurus\Form;

use Laminas\Form\Element;
use Laminas\Form\Fieldset;
use Omeka\Form\Element\PropertySelect;

class ThesaurusFieldset extends Fieldset
{
    public function init(): void
    {
        $this
            ->add([
                'name' => 'o:block[__blockIndex__][o:data][heading]',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Block title', // @translate
                    'info' => 'Heading for the block, if any.', // @translate
                ],
                'attributes' => [
                    'id' => 'thesaurus-heading',
                ],
            ])
            ->add([
                'name' => 'o:block[__blockIndex__][o:data][item]',
                'type' => Element\Number::class,
                'options' => [
                    'label' => 'Scheme or concept id', // @translate
                ],
                'attributes' => [
                    'id' => 'thesaurus-item',
                    'required' => true,
                    'min' => 1,
                ],
            ])
            ->add([
                'name' => 'o:block[__blockIndex__][o:data][type]',
                'type' => Element\Select::class,
                'options' => [
                    'label' => 'Type of display', // @translate
                    'empty_option' => '',
                    'value_options' => [
                        'root' => 'Root', // @translate
                        'broader' => 'Broader', // @translate
                        'tops' => 'Top concepts', // @translate
                        'narrowers' => 'Narrower concepts', // @translate
                        'relateds' => 'Related concepts', // @translate
                        'siblings' => 'Sibling concepts', // @translate
                        'siblingsOrSelf' => 'Sibling concepts or self', // @translate
                        'ascendants' => 'Ascendants as list', // @translate
                        'descendants' => 'Descendant as list', // @translate
                        'tree' => 'Tree structure', // @translate
                        'branch' => 'Branch', // @translate
                        'flatTree' => 'Flat tree', // @translate
                        'flatBranch' => 'Flat branch', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'thesaurus-type',
                    'required' => true,
                    'multiple' => false,
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select type…', // @translate
                ],
            ])
            ->add([
                'name' => 'o:block[__blockIndex__][o:data][link]',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Type of link', // @translate
                    'empty_option' => '',
                    'value_options' => [
                        'term' => 'Terms', // @translate
                        'resource' => 'Resources', // @translate
                        'both' => 'Both', // @translate
                        'none' => 'None', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'thesaurus-link',
                    'required' => true,
                ],
            ])
            ->add([
                'name' => 'o:block[__blockIndex__][o:data][term]',
                'type' => PropertySelect::class,
                'options' => [
                    'label' => 'Property for links', // @translate
                    'info' => 'Generally, it is "dcterms:subject".', // @translate
                    'empty_option' => '',
                    'term_as_value' => true,
                ],
                'attributes' => [
                    'id' => 'tree-structure-term',
                    'required' => true,
                    'multiple' => false,
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select property…', // @translate
                ],
            ])
            ->add([
                'name' => 'o:block[__blockIndex__][o:data][hideIfEmpty]',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Hide if empty', // @translate
                ],
                'attributes' => [
                    'id' => 'thesaurus-hide-if-empty',
                ],
            ])
            ->add([
                'name' => 'o:block[__blockIndex__][o:data][expanded]',
                'type' => Element\Number::class,
                'options' => [
                    'label' => 'Level expanded', // @translate
                    'info' => 'Set 0 to start closed, a big number to display all levels.', // @translate
                ],
                'attributes' => [
                    'id' => 'thesaurus-expanded',
                ],
            ])
        ;
        if (class_exists('BlockPlus\Form\Element\TemplateSelect')) {
            $this
                ->add([
                    'name' => 'o:block[__blockIndex__][o:data][template]',
                    'type' => \BlockPlus\Form\Element\TemplateSelect::class,
                    'options' => [
                        'label' => 'Template to display', // @translate
                        'info' => 'Templates are in folder "common/block-layout" of the theme and should start with "thesaurus".', // @translate
                        'template' => 'common/block-layout/thesaurus',
                    ],
                    'attributes' => [
                        'class' => 'chosen-select',
                    ],
                ])
            ;
        }
    }
}
