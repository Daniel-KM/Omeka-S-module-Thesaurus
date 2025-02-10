<?php declare(strict_types=1);

namespace Thesaurus\Form;

use Laminas\Form\Element;
use Laminas\Form\Form;
use Omeka\Form\Element as OmekaElement;

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
                        'tab_offset_code_prepended' => 'Tabulation offsets with prepended codes', // @translate
                        'tab_offset_code_appended' => 'Tabulation offsets with appended codes', // @translate
                        'structure_label' => 'Structure and label (01-02-03 xxx)', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'format',
                    'value' => 'tab_offset',
                ],
            ])

            ->add([
                'name' => 'codes',
                'type' => OmekaElement\ArrayTextarea::class,
                'options' => [
                    'label' => 'Property mapping for prepended or appended codes', // @translate
                    'info' => 'Set codes mapped with the property. A property can be set directly when used in input table.', // @translate
                    'as_key_value' => true,
                ],
                'attributes' => [
                    'id' => 'codes',
                    'rows' => 12,
                    'value' => [
                        /** @see https://opentheso.hypotheses.org/67 */
                        'UF' => 'skos:altLabel',
                        // 'BT' => 'skos:broader',
                        // 'NT' => 'skos:narrower',
                        // 'RT' => 'skos:related',
                        'SN' => 'skos:scopeNote',
                        'CC' => 'skos:notation',
                        // French.
                        // Equivalence: Used for / Employé pour.
                        'EP' => 'skos:altLabel',
                        // HIerarchy: Broader term / Terme générique.
                        // 'TG' => 'skos:broader',
                        // Hierarchy: Narrower term / Terme spécifique.
                        // 'TS' => 'skos:narrower',
                        // Association: Related Term / Terme associé.
                        // 'TA' => 'skos:related',
                        // Scope: Scope note / Note d’application (ou champ/domaine d’application).
                        'NA' => 'skos:scopeNote',
                        // Classification code / code de classification (notation).
                        'CC' => 'skos:notation',
                        // TODO Other codes: TT = Top term, MT = Microthesaurus, CC = Classification code, HN = History note, etc.
                        // TODO USE = EM / employer: main descriptor.
                        'dcterms:identifier' => null,
                    ],
                    'placeholder' => <<<'TXT'
                        UF = skos:altLabel
                        SN = skos:scopeNote
                        CC = skos:notation
                        EP = skos:altLabel
                        NA = skos:scopeNote
                        dcterms:identifier
                        TXT,
                ],
            ])

            ->add([
                'name' => 'skip_first_line',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Skip first line', // @translate
                ],
                'attributes' => [
                    'id' => 'skip_first_line',
                ],
            ])

            ->add([
                'name' => 'clean',
                'type' => Element\MultiCheckbox::class,
                'options' => [
                    'label' => 'Clean input', // @translate
                    'value_options' => [
                        'trim_punctuation' => 'Remove trailing punctuation', // @translate
                        'apostrophe' => 'Replace single quote by apostrophe', // @translate
                        'single_quote' => 'Replace apostrophe by single quote', // @translate
                        'lowercase' => 'Lower case for string', // @translate
                        'ucfirst' => 'Lower case for string and upper case for first letter', // @translate
                        'ucwords' => 'Lower case for string and upper case for each word', // @translate
                        'uppercase' => 'Upper case for string', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'clean',
                    'value' => [
                        // 'trim_punctuation',
                    ],
                ],
            ])

            ->add([
                'name' => 'output',
                'type' => Element\Select::class,
                'options' => [
                    'label' => 'Output', // @translate
                    'value_options' => [
                        'structure' => [
                            'label' => 'Structure only (flat list for custom vocabulary)',
                            'options' => [
                                'text' => 'As text to copy paste', // @translate
                                'file' => 'As file', // @translate
                                'thesaurus' => 'As a new thesaurus (check as text first)', // @translate
                            ],
                        ],
                        'thesaurus' => [
                            'label' => 'Structure and semantical relations (when there are coded relations)',
                            'options' => [
                                'thesaurus_full' => 'As a new thesaurus', // @translate
                            ],
                        ],
                    ],
                ],
                'attributes' => [
                    'id' => 'output',
                    'value' => 'text',
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
                    'class' => 'button',
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
                'name' => 'codes',
                'required' => false,
            ])
            ->add([
                'name' => 'skip_first_line',
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
