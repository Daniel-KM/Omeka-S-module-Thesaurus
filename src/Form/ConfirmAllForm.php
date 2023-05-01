<?php declare(strict_types=1);

namespace Thesaurus\Form;

use Laminas\Form\Element;
use Omeka\Form\ConfirmForm;

/**
 * General form for confirming an irreversible action in a sidebar.
 *
 * Extend Omeka ConfirmForm to simplify some checks of the confirm form.
 */
class ConfirmAllForm extends ConfirmForm
{
    public function init()
    {
        $this
            ->add([
                'name' => 'mode',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Limit deletion', // @translate
                    'value_options' => [
                        'scheme' => 'Scheme only', // @translate
                        'full' => 'Full thesaurus (scheme and all concepts)', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'mode',
                    'value' => 'scheme',
                ],
            ]);

        parent::init();
    }
}
