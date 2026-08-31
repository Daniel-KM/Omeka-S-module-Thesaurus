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

    /**
     * Get the thesaurus helper set on this concept, to navigate the hierarchy.
     *
     * All the methods below delegate to it, so the tree logic has a single
     * source (the stdlib), without duplication in the representation.
     */
    public function thesaurus(): \Thesaurus\Stdlib\Thesaurus
    {
        $thesaurus = $this->getServiceLocator()->get('Thesaurus\Thesaurus');
        return $thesaurus($this);
    }

    public function isSkos(): bool
    {
        return $this->thesaurus()->isSkos();
    }

    public function isConcept(): bool
    {
        return $this->thesaurus()->isConcept();
    }

    public function isRoot(): bool
    {
        $root = $this->resource->getRoot();
        return $root
            ? $root->getId() === $this->id()
            : $this->resource->getBroader() === null;
    }

    public function top()
    {
        return $this->thesaurus()->top();
    }

    public function tops(): array
    {
        return $this->thesaurus()->tops();
    }

    public function relateds(): array
    {
        return $this->thesaurus()->relateds();
    }

    public function siblings(): array
    {
        return $this->thesaurus()->siblings();
    }

    public function siblingsOrSelf(): array
    {
        return $this->thesaurus()->siblingsOrSelf();
    }

    public function ascendants(bool $fromTop = false): array
    {
        return $this->thesaurus()->ascendants($fromTop);
    }

    public function ascendantsOrSelf(bool $fromTop = false): array
    {
        return $this->thesaurus()->ascendantsOrSelf($fromTop);
    }

    public function descendants(): array
    {
        return $this->thesaurus()->descendants();
    }

    public function descendantsOrSelf(): array
    {
        return $this->thesaurus()->descendantsOrSelf();
    }

    public function tree(): array
    {
        return $this->thesaurus()->tree();
    }

    public function branch(): array
    {
        return $this->thesaurus()->branch();
    }

    public function flatTree(): array
    {
        return $this->thesaurus()->flatTree();
    }

    public function flatBranch(): array
    {
        return $this->thesaurus()->flatBranch();
    }
}
