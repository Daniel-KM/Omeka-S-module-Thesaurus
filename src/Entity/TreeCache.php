<?php declare(strict_types=1);

namespace Thesaurus\Entity;

use DateTime;
use Omeka\Entity\AbstractEntity;
use Omeka\Entity\Item;

/**
 * The relations of the concepts of a thesaurus, kept to build its tree quickly.
 *
 * Only the relations are stored, with the visibility of their values: the list
 * of the concepts and their titles are read on each request via the api, so the
 * visibility for the current user is always checked.
 *
 * @Entity
 * @Table(
 *     name="thesaurus_tree_cache"
 * )
 */
class TreeCache extends AbstractEntity
{
    /**
     * @var Item
     *
     * @Id
     * @OneToOne(
     *     targetEntity="Omeka\Entity\Item"
     * )
     * @JoinColumn(
     *     name="scheme_id",
     *     nullable=false,
     *     onDelete="CASCADE"
     * )
     */
    protected $scheme;

    /**
     * @var DateTime
     *
     * @Column(
     *     type="datetime"
     * )
     */
    protected $created;

    /**
     * The ordered relations "broader" and "narrower", with their visibility.
     *
     * @var array
     *
     * @Column(
     *     type="json"
     * )
     */
    protected $data;

    public function getId()
    {
        return $this->scheme ? $this->scheme->getId() : null;
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

    public function setCreated(DateTime $created): self
    {
        $this->created = $created;
        return $this;
    }

    public function getCreated(): DateTime
    {
        return $this->created;
    }

    public function setData(array $data): self
    {
        $this->data = $data;
        return $this;
    }

    public function getData(): array
    {
        return $this->data;
    }
}
