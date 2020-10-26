<?php declare(strict_types=1);

namespace Thesaurus\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Omeka\Entity\AbstractEntity;
use Omeka\Entity\Item;

/**
 * There are two possibilities to manage the thesaurus term object: either a
 * recursive entity, either a list of term. In practice, the broader and root
 * may be terms or items.
 * Here, the tree structure is managed as values and the table is mainly
 * designed for quick access, in particular to get the whole set. Advantages to
 * use a list of terms is to create the terms independantly one by one, not from
 * the root. Nevertheless, the use of the tree structure as term seem more
 * generic and allow complex queries on one table. In all cases, a re-indexation
 * is required to get the positions each time an item that is a term is updated.
 *
 * @Entity
 * @Table(
 *     uniqueConstraints={
 *         @UniqueConstraint(
 *             columns={
 *                 "item_id",
 *                 "scheme_id"
 *             }
 *         )
 *     }
 * )
 */
class Term extends AbstractEntity
{
    /**
     * @var int
     * @Id
     * @Column(
     *      type="integer"
     * )
     * @GeneratedValue
     */
    protected $id;

    /**
     * @var \Omeka\Entity\Item
     * @ManyToOne(
     *     targetEntity="Omeka\Entity\Item",
     *     inversedBy="term"
     * )
     * @JoinColumn(
     *     nullable=false,
     *     onDelete="CASCADE"
     * )
     */
    protected $item;

    /**
     * @var \Omeka\Entity\Item
     * @ManyToOne(
     *     targetEntity="Omeka\Entity\Item",
     *     inversedBy="term"
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
     * @var Term
     * @ManyToOne(
     *     targetEntity="Term",
     *     inversedBy="root"
     * )
     * @JoinColumn(
     *     nullable=true,
     *     onDelete="CASCADE"
     * )
     */
    protected $root;

    /**
     * @var Term
     * @ManyToOne(
     *     targetEntity="Term",
     *     inversedBy="narrowers"
     * )
     * @JoinColumn(
     *     nullable=true,
     *     onDelete="CASCADE"
     * )
     */
    protected $broader;

    /**
     * @var Term[]
     * @OneToMany(
     *     targetEntity="Term",
     *     mappedBy="broader",
     *     orphanRemoval=true,
     *     cascade={
     *         "persist",
     *         "remove",
     *         "detach"
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
     * @Column(
     *      type="integer",
     *      nullable=true
     * )
     */
    protected $position;

    public function __construct()
    {
        $this->narrowers = new ArrayCollection;
    }

    public function getId()
    {
        return $this->id;
    }

    /**
     * @param Item $item
     * @return self
     */
    public function setItem(Item $item)
    {
        $this->item = $item;
        return $this;
    }

    /**
     * @return \Omeka\Entity\Item
     */
    public function getItem()
    {
        return $this->item;
    }

    /**
     * @param Item $scheme
     * @return self
     */
    public function setScheme(Item $scheme)
    {
        $this->scheme = $scheme;
        return $this;
    }

    /**
     * @return \Omeka\Entity\Item
     */
    public function getScheme()
    {
        return $this->scheme;
    }

    /**
     * @param Term $root
     * @return self
     */
    public function setRoot(Term $root = null)
    {
        $this->root = $root;
        return $this;
    }

    /**
     * @return \Thesaurus\Entity\Term
     */
    public function getRoot()
    {
        return $this->root;
    }

    /**
     * @param Term $broader
     * @return self
     */
    public function setBroader(Term $broader = null)
    {
        $this->broader = $broader;
        return $this;
    }

    /**
     * @return \Thesaurus\Entity\Term
     */
    public function getBroader()
    {
        return $this->broader;
    }

    /**
     * @return \Thesaurus\Entity\Term[]
     */
    public function getNarrowers()
    {
        return $this->narrowers;
    }

    /**
     * @param int $position
     * @return self
     */
    public function setPosition($position = null)
    {
        $this->position = (int) $position;
        return $this;
    }

    /**
     * @return int
     */
    public function getPosition()
    {
        return $this->position;
    }
}
