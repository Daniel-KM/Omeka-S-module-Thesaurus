<?php
namespace Thesaurus\Form;

use Laminas\Form\Element;
use Laminas\Form\Form;

class ConvertForm extends Form
{
    public function init()
    {
        // The action attribute is set via the controller.

        $this->add([
            'name' => 'thesaurus',
            'type' => 'file',
            'options' => [
                'label' => 'Thesaurus text file', // @translate
                'info' => 'The thesaurus is a simple txt file, with one term by line, and tabulations to indicate the hierarchic level.', //@translate
            ],
            'attributes' => [
                'id' => 'txt',
                'required' => 'true',
            ],
        ]);

        $this->add([
            'name' => 'submit-upload',
            'type' => Element\Button::class,
            'options' => [
                'label' => 'Upload thesaurus', // @translate
            ],
            'attributes' => [
                'type' => 'submit',
                'title' => 'Submit',
            ],
        ]);

        $inputFilter = $this->getInputFilter();
        $inputFilter->add([
            'name' => 'thesaurus',
            'required' => true,
        ]);
    }
}
