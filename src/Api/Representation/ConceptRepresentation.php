<?php declare(strict_types=1);

namespace Thesaurus\Api\Representation;

use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\ItemRepresentation;

class ConceptRepresentation extends AbstractResourceEntityRepresentation
{
    public function getControllerName()
    {
        return 'concept';
    }

    public function getResourceJsonLdType()
    {
        return 'o-module-thesaurus:Concept';
    }

    public function getResourceJsonLd()
    {
        return [
            'o:scheme' => $this->scheme()->getReference(),
            'o:broader' => ($broader = $this->broader()) ? $broader->getReference() : null,
            'o:root' => ($root = $this->root()) ? $root->getReference() : null,
            'o:position' => $this->position(),
        ];
    }

    public function scheme(): ItemRepresentation
    {
        return $this->getAdapter('items')->getRepresentation($this->resource->getScheme());
    }

    public function broader(): ?ConceptRepresentation
    {
        $broader = $this->resource->getBroader();
        return $broader ? $this->getAdapter('concepts')->getRepresentation($broader) : null;
    }

    public function root(): ?ConceptRepresentation
    {
        $root = $this->resource->getRoot();
        return $root ? $this->getAdapter('concepts')->getRepresentation($root) : null;
    }

    public function position(): ?int
    {
        return $this->resource->getPosition();
    }

    /**
     * @return ConceptRepresentation[]
     */
    public function narrowers(): array
    {
        $adapter = $this->getAdapter('concepts');
        $narrowers = [];
        foreach ($this->resource->getNarrowers() as $narrower) {
            $narrowers[$narrower->getId()] = $adapter->getRepresentation($narrower);
        }
        return $narrowers;
    }
}
