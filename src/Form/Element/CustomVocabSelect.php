<?php declare(strict_types=1);

namespace Thesaurus\Form\Element;

use CustomVocab\Api\Representation\CustomVocabRepresentation;
use Thesaurus\Mvc\Controller\Plugin\Thesaurus;

/**
 * This select should extend CustomVocabSelect to be compliant with CustomVocab
 * representation.
 */
class CustomVocabSelect extends \CustomVocab\Form\Element\CustomVocabSelect
{
    /**
     * @var Thesaurus
     */
    protected $thesaurus;

    /**
     * @var string
     */
    protected $display;

    public function getValueOptions() : array
    {
        $customVocabId = $this->getOption('custom_vocab_id');

        try {
            /** @var \CustomVocab\Api\Representation\CustomVocabRepresentation $customVocab */
            $customVocab = $this->api->read('custom_vocabs', $customVocabId)->getContent();
        } catch (\Omeka\Api\Exception\NotFoundException $e) {
            return [];
        }

        $valueOptions = null;

        // CustomVocab v2.0.0 changed method names.
        $customVocabType = method_exists($customVocab, 'typeValues')
            ? $customVocab->typeValues()
            : $customVocab->type();
        if ($customVocabType === 'resource') {
            $valueOptions = $this->listValuesThesaurus($customVocab);
        }
        $valueOptions ??= $customVocab->listValues($this->getOptions());

        $prependValueOptions = $this->getOption('prepend_value_options');
        if (is_array($prependValueOptions)) {
            $valueOptions = $prependValueOptions + $valueOptions;
        }

        $this->setValueOptions($valueOptions);
        return $valueOptions;
    }

    public function setThesaurus(Thesaurus $thesaurus): self
    {
        $this->thesaurus = $thesaurus;
        return $this;
    }

    public function setDefaultDisplay(string $display): self
    {
        $this->display = $display;
        return $this;
    }

    protected function listValuesThesaurus(CustomVocabRepresentation $customVocab): ?array
    {
        // Check if the item set id is a skos item set.
        $itemSet = $customVocab->itemSet();
        if (!$itemSet) {
            return null;
        }

        $thesaurus = $this->thesaurus->__invoke($itemSet);
        if (!$thesaurus->isSkos()) {
            return null;
        }

        $options = $this->getOptions();
        if ($this->display === 'indent') {
            $options += [
                'indent' => '– ',
            ];
        } else {
            $options += [
                'ascendance' => true,
            ];
        }

        // To append the id is useless, since the list is ordered and indented.
        /*
        if (!empty($options['append_id_to_title'])) {
            $options['append_id'] = true;
        }
        */

        return $thesaurus->listTree($options);
    }
}
