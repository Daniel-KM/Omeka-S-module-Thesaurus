<?php declare(strict_types=1);

namespace Thesaurus\Entity;

use DateTime;
use Omeka\Entity\AbstractEntity;

/**
 * A backup of the data removed or modified by a conversion into concepts.
 *
 * The backup is stored in the database, so it is included in the backups of
 * the database and it does not depend on the rights of the file system. It is
 * not stored in the settings, that are fully loaded on each request.
 *
 * @Entity
 * @Table(
 *     name="thesaurus_backup"
 * )
 */
class Backup extends AbstractEntity
{
    /**
     * @var int
     *
     * @Id
     * @Column(
     *     type="integer"
     * )
     * @GeneratedValue
     */
    protected $id;

    /**
     * @var DateTime
     *
     * @Column(
     *     type="datetime"
     * )
     */
    protected $created;

    /**
     * Ids of the thesaurus.
     *
     * @var array
     *
     * @Column(
     *     type="json"
     * )
     */
    protected $schemes;

    /**
     * The rows, the data types of the values and the templates.
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
        return $this->id;
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

    public function setSchemes(array $schemes): self
    {
        $this->schemes = $schemes;
        return $this;
    }

    public function getSchemes(): array
    {
        return $this->schemes;
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
