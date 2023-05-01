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
                'name' => 'clean',
                'type' => Element\MultiCheckbox::class,
                'options' => [
                    'label' => 'Clean input', // @translate
                    'value_options' => [
                        'trim_punctuation' => 'Remove trailing punctuation', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'clean',
                    'value' => [
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
                    'name' => 'clean',
                    'required' => false,
                ])
                ->add([
                    'name' => 'output',
                    'required' => false,
                ]);
    }
}
