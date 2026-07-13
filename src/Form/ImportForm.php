<?php declare(strict_types=1);

namespace Thesaurus\Form;

use Common\Form\Element as CommonElement;
use Laminas\Form\Element;
use Laminas\Form\Form;
use Omeka\Form\Element as OmekaElement;

class ImportForm extends Form
{
    public function init(): void
    {
        // The action attribute is set via the controller.

        $this
            ->add([
                'name' => 'file',
                'type' => Element\File::class,
                'options' => [
                    'label' => 'Source file', // @translate
                ],
                'attributes' => [
                    'id' => 'file',
                ],
            ])

            ->add([
                'name' => 'url',
                'type' => Element\Url::class,
                'options' => [
                    'label' => 'Or import from a URL', // @translate
                ],
                'attributes' => [
                    'id' => 'url',
                ],
            ])

            ->add([
                'name' => 'format',
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Input format', // @translate
                    'value_options' => [
                        'skos' => 'Standard SKOS (RDF/XML, Turtle, JSON-LD, N-Triples)', // @translate
                        'tab_offset' => 'Tabulation offsets', // @translate
                        'tab_offset_code_prepended' => 'Tabulation offsets with prepended codes', // @translate
                        'tab_offset_code_appended' => 'Tabulation offsets with appended codes', // @translate
                        'structure_label' => 'Structure and label (01-02-03 xxx)', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'format',
                    'value' => 'skos',
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
                        // Hierarchy: Broader term / Terme générique.
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
                'name' => 'skos',
                'type' => CommonElement\OptionalMultiCheckbox::class,
                'options' => [
                    'label' => 'SKOS values to import', // @translate
                    'info' => 'The preferred labels and the hierarchy are always imported. The preview does not show all the imported values.', // @translate
                    'value_options' => [
                        'multilingual' => 'Multilingual (else the Omeka admin language)', // @translate
                        'documentation' => 'Documentation (definition, scope note, notes…)', // @translate
                        'notation' => 'Notation', // @translate
                        'relations' => 'Internal relations (related)', // @translate
                        'mappings' => 'External alignments (exactMatch, closeMatch…)', // @translate
                        'other_vocabularies' => 'Other vocabularies (imported if the property exists in Omeka, else logged)', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'skos',
                    // All but the non-skos vocabularies.
                    'value' => [
                        'multilingual',
                        'documentation',
                        'notation',
                        'relations',
                        'mappings',
                    ],
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
                'type' => CommonElement\OptionalMultiCheckbox::class,
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
                'name' => 'destination',
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Destination', // @translate
                    'value_options' => [
                        'preview' => 'Preview the flat list (to check or copy-paste)', // @translate
                        'customvocab' => 'Custom vocabulary (list of terms)', // @translate
                        'thesaurus' => 'Thesaurus (items with relations)', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'destination',
                    'value' => 'preview',
                ],
            ])

            ->add([
                'name' => 'customvocab_label',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Custom vocabulary label', // @translate
                    'info' => 'Leave empty to use the file name.', // @translate
                ],
                'attributes' => [
                    'id' => 'customvocab_label',
                ],
            ])

            ->add([
                'name' => 'customvocab_format',
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Custom vocabulary terms format', // @translate
                    'value_options' => [
                        'path' => 'Full path (Europe :: France :: Paris)', // @translate
                        'indent' => 'Indented label', // @translate
                        'label' => 'Leaf label only (may create duplicates)', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'customvocab_format',
                    'value' => 'path',
                ],
            ])

            ->add([
                'name' => 'create_customvocab',
                'type' => CommonElement\OptionalCheckbox::class,
                'options' => [
                    'label' => 'Create the linked custom vocabulary (item set)', // @translate
                    'info' => 'Optional: the thesaurus data type does not require it. It is kept as a complement for compatibility with the custom vocab.', // @translate
                ],
                'attributes' => [
                    'id' => 'create_customvocab',
                ],
            ]);

        // The source may be a file or a url, so neither is required.
        $this->getInputFilter()
            ->add([
                'name' => 'file',
                'required' => false,
            ])
            ->add([
                'name' => 'url',
                'required' => false,
            ]);
    }
}
