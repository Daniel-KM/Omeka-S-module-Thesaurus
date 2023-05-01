<?php declare(strict_types=1);

namespace Thesaurus\Form;

use Laminas\Form\Element;
use Laminas\Form\Form;

class UpdateConceptsForm extends Form
{
    public function init(): void
    {
        $this
            ->add([
                'name' => 'fill',
                'type' => Element\MultiCheckbox::class,
                'options' => [
                    'label' => 'Properties to fill for fhesaurus', // @translate
                    'info' => 'To store the path or the ascendance simplifies form filling and searches. If stored, recommended is to use alternative label for path and, if needed, hidden label for ascendance.', // @translate
                    'value_options' => [
                        /*
                        'descriptor_preflabel' => 'Store descriptor as preferred label (default)', // @translate
                        'descriptor_altlabel' => 'Store descriptor as alternative label', // @translate
                        'descriptor_hiddenlabel' => 'Store descriptor as skos hidden label', // @translate
                        */
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
                'name' => 'mode',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Mode', // @translate
                    'value_options' => [
                        'replace' => 'Replace existing values', // @translate
                        'prepend' => 'Prepend new value', // @translate
                        'append' => 'Append new value', // @translate
                        'remove' => 'Remove values', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'mode',
                    'value' => 'replace',
                ],
            ])

            ->add([
                'name' => 'separator',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Ascendance separator (with spaces)', // @translate
                    'info' => 'Usually " :: ", " -- ", " / " or " > ". Set a leading and a trailing space if needed.', // @translate
                ],
                'attributes' => [
                    'id' => 'separator',
                    'value' => ' :: ',
                ],
            ])

            ->add([
                'name' => 'submit',
                'type' => Element\Button::class,
                'options' => [
                    'label' => 'Submit', // @translate
                ],
                'attributes' => [
                    'id' => 'submit',
                    'type' => 'submit',
                    'title' => 'Submit',
                ],
            ]);
    }
}
