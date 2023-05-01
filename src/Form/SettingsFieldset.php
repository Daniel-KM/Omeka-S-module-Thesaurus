<?php declare(strict_types=1);

namespace Thesaurus\Form;

use Laminas\Form\Element;
use Laminas\Form\Fieldset;
use Thesaurus\Form\Element as ThesaurusElement;

class SettingsFieldset extends Fieldset
{
    protected $label = 'Thesaurus'; // @translate

    protected $elementGroups = [
        'thesaurus' => 'Thesaurus', // @translate
    ];

    public function init(): void
    {
        $this
            ->setAttribute('id', 'thesaurus')
            ->setOption('element_groups', $this->elementGroups)
            ->add([
                'name' => 'thesaurus_property_descriptor',
                'type' => Element\Radio::class,
                'options' => [
                    'element_group' => 'thesaurus',
                    'label' => 'Property used for the label of the descriptor', // @translate
                    'info' => 'It is not recommended to change it once set.', // @translate
                    'value_options' => [
                        'skos:prefLabel' => 'Skos preferred label (default)', // @translate
                        'skos:altLabel' => 'Skos alternative label', // @translate
                        'skos:hiddenLabel' => 'Skos hidden label (not recommended)', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'thesaurus_property_descriptor',
                ],
            ])
            ->add([
                'name' => 'thesaurus_property_path',
                'type' => ThesaurusElement\OptionalRadio::class,
                'options' => [
                    'element_group' => 'thesaurus',
                    'label' => 'Property used for the full path (ascendance and descriptor)', // @translate
                    'info' => 'To store the path or the ascendance simplifies form filling and searches.', // @translate
                    'value_options' => [
                        '' => 'None', // @translate
                        'skos:prefLabel' => 'Skos preferred label (not recommended)', // @translate
                        'skos:altLabel' => 'Skos alternative label', // @translate
                        'skos:hiddenLabel' => 'Skos hidden label', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'thesaurus_property_path',
                ],
            ])
            ->add([
                'name' => 'thesaurus_property_ascendance',
                'type' => ThesaurusElement\OptionalRadio::class,
                'options' => [
                    'element_group' => 'thesaurus',
                    'label' => 'Property used for the ascendance', // @translate
                    'info' => 'To store the path or the ascendance simplifies form filling and searches.', // @translate
                    'value_options' => [
                        '' => 'None', // @translate
                        'skos:prefLabel' => 'Skos preferred label (not recommended)', // @translate
                        'skos:altLabel' => 'Skos alternative label (not recommended)', // @translate
                        'skos:hiddenLabel' => 'Skos hidden label (if needed)', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'thesaurus_property_ascendance',
                ],
            ])

            ->add([
                'name' => 'thesaurus_separator',
                'type' => Element\Text::class,
                'options' => [
                    'element_group' => 'thesaurus',
                    'label' => 'Ascendance separator (with spaces)', // @translate
                    'info' => 'Usually " :: ", " -- ", " / " or " > ". Set a leading and a trailing space if needed. It must not be used in descriptors.', // @translate
                ],
                'attributes' => [
                    'id' => 'thesaurus_separator',
                ],
            ])

            ->add([
                'name' => 'thesaurus_select_display',
                'type' => Element\Radio::class,
                'options' => [
                    'element_group' => 'thesaurus',
                    'label' => 'Display of html "select" options', // @translate
                    'value_options' => [
                        'ascendance' => 'Full path', // @translate
                        'indent' => 'Indented', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'thesaurus_select_display',
                ],
            ])
        ;
    }
}
