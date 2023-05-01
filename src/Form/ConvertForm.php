<?php declare(strict_types=1);

namespace Thesaurus\Form;

use Laminas\Form\Element;
use Laminas\Form\Form;

class ConvertForm extends Form
{
    public function init(): void
    {
        // The action attribute is set via the controller.

        $this
            ->add([
                'name' => 'file',
                'type' => Element\File::class,
                'options' => [
                    'label' => 'Thesaurus text file', // @translate
                    'info' => 'The thesaurus is a simple txt file, with one concept by line, and tabulations to indicate the hierarchic level.', //@translate
                ],
                'attributes' => [
                    'id' => 'file',
                    'required' => 'required',
                ],
            ])

            ->add([
                'name' => 'format',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Input format', // @translate
                    'value_options' => [
                        'tab_offset' => 'Tabulation offsets', // @translate
                        'structure_label' => 'Structure and label (01-02-03 xxx)', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'format',
                    'value' => 'tab_offset',
                ],
            ])

            ->add([
                'name' => 'output',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Output', // @translate
                    'value_options' => [
                        'text' => 'As text to copy paste', // @translate
                        'file' => 'As file', // @translate
                        'thesaurus' => 'As a new thesaurus (check as text first)', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'output',
                    'value' => 'text',
                ],
            ])

            ->add([
                'name' => 'fill',
                'type' => Element\MultiCheckbox::class,
                'options' => [
                    'label' => 'Properties to fill for fhesaurus', // @translate
                    'info' => 'To store the path or the ascendance simplifies form filling and searches. Recommended is to use alternative label for path and, if needed, hidden label for ascendance.', // @translate
                    'value_options' => [
                        'descriptor_preflabel' => 'Store descriptor as preferred label (default)', // @translate
                        'descriptor_altlabel' => 'Store descriptor as alternative label', // @translate
                        'descriptor_hiddenlabel' => 'Store descriptor as skos hidden label', // @translate
                        'path_preflabel' => 'Store full path (ascendance and descriptor) as preferred label (not recommended: set default title in template instead)', // @translate
                        'path_altlabel' => 'Store full path (ascendance and descriptor) as alternative label', // @translate
                        'path_hiddenlabel' => 'Store full path (ascendance and descriptor) as skos hidden label', // @translate
                        'ascendance_preflabel' => 'Store ascendance as preferred label (not recommended: set default title in template instead)', // @translate
                        'ascendance_altlabel' => 'Store ascendance as alternative label', // @translate
                        'ascendance_hiddenlabel' => 'Store ascendance as skos hidden label', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'fill',
                    'value' => [
                        'descriptor_preflabel',
                    ],
                ],
            ])

            ->add([
                'name' => 'clean',
                'type' => Element\MultiCheckbox::class,
                'options' => [
                    'label' => 'Clean input', // @translate
                    'value_options' => [
                        'replace_html_entities' => 'Replace html entities with unicode', // @translate
                        'trim_punctuation' => 'Remove trailing punctuation', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'clean',
                    'value' => [
                        'replace_html_entities',
                        'trim_punctuation',
                    ],
                ],
            ])

            ->add([
                'name' => 'submit-upload',
                'type' => Element\Button::class,
                'options' => [
                    'label' => 'Upload thesaurus', // @translate
                ],
                'attributes' => [
                    'id' => 'submit-upload',
                    'type' => 'submit',
                    'title' => 'Submit',
                ],
            ]);

            $inputFilter = $this->getInputFilter();
            $inputFilter
                ->add([
                    'name' => 'file',
                    'required' => true,
                ])
                ->add([
                    'name' => 'format',
                    'required' => false,
                ])
                ->add([
                    'name' => 'fill',
                    'required' => false,
                ])
                ->add([
                    'name' => 'clean',
                    'required' => false,
                ])
                ->add([
                    'name' => 'output',
                    'required' => false,
                ]);
    }
}
