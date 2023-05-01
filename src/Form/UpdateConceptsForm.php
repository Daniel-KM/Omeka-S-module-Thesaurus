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
