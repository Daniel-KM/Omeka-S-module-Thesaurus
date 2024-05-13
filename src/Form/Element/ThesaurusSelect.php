<?php declare(strict_types=1);

namespace Thesaurus\Form\Element;

use Common\Form\Element\TraitOptionalElement;
use Laminas\Form\Element\Select;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Thesaurus\Stdlib\Thesaurus;

class ThesaurusSelect extends Select
{
    use TraitOptionalElement;

    /**
     * @var \Thesaurus\Stdlib\Thesaurus
     */
    protected $thesaurusLib;

    /**
     * @var \Omeka\Api\Representation\ItemRepresentation|\Omeka\Api\Representation\ItemSetRepresentation|int
     */
    protected $thesaurusTerm;

    /**
     * @see self::getValueOptions()
     *
     * {@inheritDoc}
     * @see \Laminas\Form\Element\Select::setOptions()
     */
    public function setOptions($options)
    {
        if (isset($options['thesaurus'])) {
            $this->setThesaurusTerm($options['thesaurus']);
        }

        parent::setOptions($options);

        return $this;
    }

    public function setThesaurus(Thesaurus $thesaurus): self
    {
        $this->thesaurusLib = $thesaurus;
        return $this;
    }

    public function setThesaurusTerm($term): self
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
        if (is_int($this->thesaurusTerm) && $this->thesaurusLib) {
            $this->thesaurusTerm = $this->thesaurusLib->__invoke()->itemFromData($this->thesaurusTerm) ?: null;
        }
        return is_int($this->thesaurusTerm) ? null : $this->thesaurusTerm;
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
     *   - ascendance (bool): Prepend the ascendants. False by default, so flat.
     *   - separator (string): Ascendance separator (with spaces).
     *   - indent (string): String like "– " to prepend to terms to show level.
     *   - prepend_id (bool): Prepend the id of the terms.
     *   - append_id (bool): Append the id of the terms.
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
        if ($thesaurusResource && $this->thesaurusLib) {
            /** @var \Thesaurus\Stdlib\Thesaurus $theso */
            $theso = $this->thesaurusLib->__invoke($thesaurusResource);
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
