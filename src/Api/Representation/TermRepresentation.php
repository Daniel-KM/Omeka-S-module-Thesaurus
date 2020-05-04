<?php

namespace Thesaurus\Api\Representation;

use Omeka\Api\Representation\AbstractEntityRepresentation;

class TermRepresentation extends AbstractEntityRepresentation
{
    protected $maxAncestors = 100;

    public function getJsonLdType()
    {
        return 'skos:Concept';
    }

    public function getJsonLd()
    {
        $scheme = $this->scheme()->getReference();
        $json = [
            'o:item' => $this->item()->getReference(),
            'skos:inScheme' => $scheme,
        ];
        $broader = $this->broader();
        if ($broader) {
            $json['skos:broader'] = $broader->getReference();
        } else {
            $json['skos:topConceptOf'] = $scheme;
        }
        return $json;
    }

    /**
     * @return \Omeka\Api\Representation\ItemRepresentation
     */
    public function item()
    {
        return $this->getAdapter('items')
            ->getRepresentation($this->resource->getItem());
    }

    /**
     * @return \Omeka\Api\Representation\ItemRepresentation
     */
    public function scheme()
    {
        return $this->getAdapter('items')
            ->getRepresentation($this->resource->getScheme());
    }

    /**
     * @return \Thesaurus\Api\Representation\TermRepresentation
     */
    public function root()
    {
        $root = $this->resource->getRoot();
        return $this->getAdapter()
            ->getRepresentation($root);
    }

    /**
     * @return \Thesaurus\Api\Representation\TermRepresentation|null
     */
    public function broader()
    {
        $broader = $this->resource->getBroader();
        if (!$broader) {
            return null;
        }
        return $this->getAdapter()
            ->getRepresentation($broader);
    }

    /**
     * @return \Thesaurus\Api\Representation\TermRepresentation[]
     */
    public function narrowers()
    {
        $narrowers = [];
        $adapter = $this->getAdapter();
        foreach ($this->resource->getNarrowers() as $entity) {
            $narrowers[$entity->getId()] = $adapter->getRepresentation($entity);
        }
        return $narrowers;
    }

    /**
     * @return int
     */
    public function position()
    {
        return $this->resource->getPosition();
    }
}
