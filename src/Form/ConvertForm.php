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
                'name' => 'thesaurus',
                'type' => Element\File::class,
                'options' => [
                    'label' => 'Thesaurus text file', // @translate
                    'info' => 'The thesaurus is a simple txt file, with one concept by line, and tabulations to indicate the hierarchic level.', //@translate
                ],
                'attributes' => [
                    'id' => 'thesaurus',
                    'required' => 'required',
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
                    'name' => 'thesaurus',
                    'required' => true,
                ]);
    }
}
