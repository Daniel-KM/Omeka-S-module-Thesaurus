<?php

namespace Thesaurus\Form\Element;

use Omeka\Api\Exception\NotFoundException;
use Omeka\Api\Manager as ApiManager;
use Thesaurus\Mvc\Controller\Plugin\Thesaurus;
use Zend\Form\Element\Select;

class ThesaurusSelect extends Select
{
    /**
     * @var ApiManager
     */
    protected $api;

    /**
     * Make the select optional when it is not required.
     *
     * @link https://github.com/zendframework/zendframework/issues/2761#issuecomment-14488216
     *
     * {@inheritDoc}
     * @see \Zend\Form\Element\Select::getInputSpecification()
     */
    public function getInputSpecification()
    {
        $inputSpecification = parent::getInputSpecification();
        $inputSpecification['required'] = !empty($this->attributes['required']);
        return $inputSpecification;
    }

    public function setOptions($options)
    {
        parent::setOptions($options);

        if (!empty($options['thesaurus'])) {
            if (is_numeric($options['thesaurus'])) {
                return $this
                    ->setThesaurusValueOptions($options['thesaurus']);
            }

            if (is_array($options['thesaurus'])
                && isset($options['thesaurus']['term'])
                && is_numeric($options['thesaurus']['term'])
            ) {
                $thesaurusOptions = empty($options['thesaurus']['options']) || !is_array($options['thesaurus']['options'])
                    ? []
                    : $options['thesaurus']['options'];
                return $this
                    ->setThesaurusValueOptions((int) $options['thesaurus']['term'], $thesaurusOptions);
            }
        }

        $prependValueOptions = $this->getOption('prepend_value_options');
        if (is_array($prependValueOptions)) {
            $this->setValueOptions($prependValueOptions);
        }
        return $this;
    }

    /**
     * Prepare the value options with the thesaurus terms.
     *
     * @param int $termId The scheme or concept item id.
     * @param array $options
     * @return self
     */
    public function setThesaurusValueOptions($termId, array $options = null)
    {
        $prependValueOptions = $this->getOption('prepend_value_options');
        if (!is_array($prependValueOptions)) {
            $prependValueOptions = [];
        }

        try {
            $item = $this->api->read('items', ['id' => $termId])->getContent();
        } catch (NotFoundException $e) {
            return $this
                ->setValueOptions($prependValueOptions);
        }

        $theso = $this->thesaurus->__invoke($item);
        if (!$theso->isSkos()) {
            return $this
                ->setValueOptions($prependValueOptions);
        }

        $valueOptions = $theso->listTree($options);

        $valueOptions = $prependValueOptions + $valueOptions;
        return $this
            ->setValueOptions($valueOptions);
    }

    public function setApiManager(ApiManager $api)
    {
        $this->api = $api;
        return $this;
    }

    public function setThesaurus(Thesaurus $thesaurus)
    {
        $this->thesaurus = $thesaurus;
        return $this;
    }
}
