<?php declare(strict_types=1);

namespace Thesaurus\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Omeka\Entity\Item;
use Omeka\Entity\Resource;

/**
 * A concept of a thesaurus.
 *
 * It is now a dedicated resource type, no more mixed with items in browse,
 * search, facets and references. As a resource, the Concept stores its labels
 * and other metadata as values (skos:prefLabel, skos:altLabel, skos:definition,
 * skos:related, etc.). Furtheremore, most used metadata are stored in this
 * entity too for performance in tree processing and rebuild. The position is
 * stored too for quicker access.
 *
 * @Entity
 * @Table(
 *     name="concept",
 *     uniqueConstraints={
 *         @UniqueConstraint(
 *             columns={
 *                 "id",
 *                 "scheme_id"
 *             }
 *         )
 *     }
 * )
 */
class Concept extends Resource
{
    /**
     * The scheme is kept as an item: it is the public entry point of the
     * thesaurus (browse, collection), and there are few of them.
     *
     * @var \Omeka\Entity\Item
     *
     * @ManyToOne(
     *     targetEntity="Omeka\Entity\Item"
     * )
     * @JoinColumn(
     *     nullable=false,
     *     onDelete="CASCADE"
     * )
     */
    protected $scheme;

    /**
     * Root is not nullable, but doctrine use two queries internally to create
     * the entity with a self-referencing for the root items.
     *
     * @var Concept
     *
     * @ManyToOne(
     *     targetEntity="Concept"
     * )
     * @JoinColumn(
     *     nullable=true,
     *     onDelete="CASCADE"
     * )
     */
    protected $root;

    /**
     * @var Concept
     *
     * @ManyToOne(
     *     targetEntity="Concept",
     *     inversedBy="narrowers"
     * )
     * @JoinColumn(
     *     nullable=true,
     *     onDelete="CASCADE"
     * )
     */
    protected $broader;

    /**
     * @var Concept[]
     *
     * @OneToMany(
     *     targetEntity="Concept",
     *     mappedBy="broader",
     *     orphanRemoval=true,
     *     cascade={
     *         "persist",
     *         "remove",
     *          "detach"
     *     },
     *     indexBy="id"
     * )
     * @OrderBy({
     *     "position" = "ASC"
     * })
     */
    protected $narrowers;

    /**
     * @var int
     *
     * @Column(
     *      type="integer",
     *      nullable=true
     * )
     */
    protected $position;

    public function __construct()
    {
        parent::__construct();
        $this->narrowers = new ArrayCollection();
    }

    public function getResourceName(): string
    {
        return 'concepts';
    }

    public function setScheme(Item $scheme): self
    {
        $this->scheme = $scheme;
        return $this;
    }

    public function getScheme(): Item
    {
        return $this->scheme;
    }

    public function setRoot(?Concept $root = null): self
    {
        $this->root = $root;
        return $this;
    }

    public function getRoot(): ?Concept
    {
        return $this->root;
    }

    public function setBroader(?Concept $broader = null): self
    {
        $this->broader = $broader;
        return $this;
    }

    public function getBroader(): ?Concept
    {
        return $this->broader;
    }

    public function getNarrowers(): Collection
    {
        return $this->narrowers;
    }

    public function setPosition(?int $position = null): self
    {
        $this->position = $position;
        return $this;
    }

    public function getPosition(): ?int
    {
        return $this->position;
    }
}
