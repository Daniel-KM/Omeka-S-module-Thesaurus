<?php declare(strict_types=1);

namespace Thesaurus\Form\Element;

use Laminas\Form\Element\Select;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Thesaurus\Mvc\Controller\Plugin\Thesaurus;

class ThesaurusSelect extends Select
{
    /**
     * @var \Thesaurus\Mvc\Controller\Plugin\Thesaurus
     */
    protected $thesaurusPlugin;

    /**
     * @var \Omeka\Api\Representation\ItemRepresentation|\Omeka\Api\Representation\ItemSetRepresentation|int
     */
    protected $thesaurusTerm;

    /**
     * Make the select optional when it is not required.
     *
     * @link https://github.com/zendframework/zendframework/issues/2761#issuecomment-14488216
     *
     * {@inheritDoc}
     * @see \Laminas\Form\Element\Select::getInputSpecification()
     */
    public function getInputSpecification(): array
    {
        $inputSpecification = parent::getInputSpecification();
        $inputSpecification['required'] = !empty($this->attributes['required']);
        return $inputSpecification;
    }

    /**
     * @see self::getValueOptions()
     *
     * {@inheritDoc}
     * @see \Laminas\Form\Element\Select::setOptions()
     */
    public function setOptions($options)
    {
        if (isset($options['thesaurus'])) {
            // @deprecated Use the term as thesaurus.
            if (is_array($options['thesaurus'])) {
                $thesaurusOptions = $options['thesaurus']['options'] ?? null;
                if (is_array($thesaurusOptions)) {
                    $options = array_merge($options, $thesaurusOptions);
                }
                if ($options['thesaurus']['term']) {
                    $this->setThesaurusTerm($options['thesaurus']['term']);
                }
            } else {
                $this->setThesaurusTerm($options['thesaurus']);
            }
        }

        parent::setOptions($options);

        return $this;
    }

    public function setThesaurus(Thesaurus $thesaurus)
    {
        $this->thesaurusPlugin = $thesaurus;
        return $this;
    }

    public function setThesaurusTerm($term)
    {
        // The term cannot be fully checked, because the thesaurus plugin may
        // not be ready.
        if ($term instanceof AbstractResourceEntityRepresentation) {
            $this->thesaurusTerm = $term;
        } elseif (is_numeric($term) && $term = (int) $term) {
            $this->thesaurusTerm = $term;
        } else {
            $this->thesaurusTerm = null;
        }
        return $this;
    }

    public function getThesaurusTerm(): ?AbstractResourceEntityRepresentation
    {
        if (is_int($this->thesaurusTerm)) {
            $term = $this->thesaurusPlugin->__invoke()->itemFromData($this->thesaurusTerm);
            if ($term) {
                $this->thesaurusTerm = $term;
            }
        }
        return $this->thesaurusTerm;
    }

    /**
     * Get values options for the select.
     *
     * Uses the passed options:
     * - standard Select options, in particular:
     *   - empty_option
     * - standard Omeka Select options:
     *   - prepend_value_options
     * - standard Thesaurus options:
     *   - output_type: "listTree" (default) or "listBranch".
     *   - indent (string): String like "– " to prepend to terms to show level.
     *   - prepend_id (bool): Prepend the id of the terms.
     *   - max_length (int): Max size of the terms.
     * - thesaurus: item (scheme or concept) or item set (skos collection), or
     *   as numeric item id.
     *
     * {@inheritDoc}
     * @see \Laminas\Form\Element\Select::getValueOptions()
     */
    public function getValueOptions(): array
    {
        $valueOptions = [];
        $thesaurusResource = $this->getThesaurusTerm();
        if ($thesaurusResource) {
            /** @var \Thesaurus\Mvc\Controller\Plugin\Thesaurus $theso */
            $theso = $this->thesaurusPlugin->__invoke($thesaurusResource);
            if ($theso->isSkos()) {
                $options = $this->getOptions();
                $thesoType = $this->getOption('output_type');
                $thesoType = $thesoType === 'listBranch' ? 'listBranch' : 'listTree';
                if ($thesoType === 'listBranch') {
                    $valueOptions = $theso->listBranch($options);
                } else {
                    $valueOptions = $theso->listTree($options);
                }
            }
        }

        $prependValueOptions = $this->getOption('prepend_value_options');
        if (!is_array($prependValueOptions)) {
            $prependValueOptions = [];
        }

        return $prependValueOptions + $valueOptions;
    }
}
