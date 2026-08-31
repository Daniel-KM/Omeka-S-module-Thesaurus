<?php declare(strict_types=1);

namespace Thesaurus\Api\Adapter;

use Common\Api\Adapter\CommonAdapterTrait;
use Doctrine\ORM\QueryBuilder;
use Omeka\Api\Adapter\AbstractResourceEntityAdapter;
use Omeka\Api\Request;
use Omeka\Entity\EntityInterface;
use Omeka\Entity\Item;
use Omeka\Entity\Resource;
use Omeka\Stdlib\ErrorStore;
use Thesaurus\Api\Representation\ConceptRepresentation;
use Thesaurus\Entity\Concept;

class ConceptAdapter extends AbstractResourceEntityAdapter
{
    use CommonAdapterTrait;

    protected $sortFields = [
        'id' => 'id',
        'title' => 'title',
        'scheme_id' => 'scheme',
        'top_id' => 'top',
        'broader_id' => 'broader',
        'position' => 'position',
        'created' => 'created',
        'modified' => 'modified',
    ];

    /**
     * Unlike the sort fields, the core uses the key as the entity field name to
     * return a scalar, so the associations are named like in the entity.
     *
     * @see \Omeka\Api\Adapter\AbstractEntityAdapter::search()
     */
    protected $scalarFields = [
        'id' => 'id',
        'title' => 'title',
        'scheme' => 'scheme',
        'top' => 'top',
        'broader' => 'broader',
        'position' => 'position',
        'is_public' => 'isPublic',
        'created' => 'created',
        'modified' => 'modified',
    ];

    /**
     * The id "0" means a null value, for example the concepts without broader,
     * that are the top concepts.
     */
    protected $queryFields = [
        'id' => [
            'scheme_id' => 'scheme',
            'top_id' => 'top',
            'broader_id' => 'broader',
        ],
        'int' => [
            'position' => 'position',
        ],
    ];

    public function getResourceName()
    {
        return 'concepts';
    }

    public function getRepresentationClass()
    {
        return \Thesaurus\Api\Representation\ConceptRepresentation::class;
    }

    public function getEntityClass()
    {
        return \Thesaurus\Entity\Concept::class;
    }

    public function buildQuery(QueryBuilder $qb, array $query): void
    {
        parent::buildQuery($qb, $query);
        $this->buildQueryFields($qb, $query);
    }

    public function hydrate(Request $request, EntityInterface $entity, ErrorStore $errorStore): void
    {
        parent::hydrate($request, $entity, $errorStore);

        $data = $request->getContent();

        // The structure of a concept can be set with the keys "o:scheme",
        // "o:broader" and "o:top", or with the skos values, that are the source
        // used by the job IndexThesaurus. The keys take precedence, so a client
        // can always set the structure explicitly.

        if ($request->getOperation() === Request::CREATE) {
            if (isset($data['o:scheme']['o:id'])) {
                $scheme = $this->getAdapter('items')->findEntity($data['o:scheme']['o:id']);
                $entity->setScheme($scheme);
            } else {
                $scheme = $this->valueResource($entity, ['skos:inScheme', 'skos:topConceptOf']);
                if ($scheme instanceof Item) {
                    $entity->setScheme($scheme);
                }
            }
        }

        // The method shouldHydrate() returns true for any key on creation, so
        // the presence of the key is checked to know if it is an explicit one.
        $hasBroader = array_key_exists('o:broader', $data) && $this->shouldHydrate($request, 'o:broader');
        if ($hasBroader) {
            $broaderId = $request->getValue('o:broader')['o:id'] ?? $request->getValue('o:broader');
            $broader = $broaderId ? $this->findEntity(['id' => $broaderId]) : null;
            $entity->setBroader($broader);
        } else {
            // A missing value does not reset the broader concept: the hierarchy
            // may be stored only as "skos:narrower" in the broader concept, so
            // it cannot be determined here. Use the job to reindex a thesaurus
            // after the removal of a relation.
            $broader = $this->valueResource($entity, ['skos:broader']);
            if ($broader instanceof Concept) {
                $entity->setBroader($broader);
                $hasBroader = true;
            }
        }

        if (array_key_exists('o:top', $data) && $this->shouldHydrate($request, 'o:top')) {
            $topId = $request->getValue('o:top')['o:id'] ?? $request->getValue('o:top');
            $top = $topId ? $this->findEntity(['id' => $topId]) : null;
            $entity->setTop($top);
        } elseif ($hasBroader) {
            // The top concept is the first ancestor, so it is the one of the
            // broader concept, or the concept itself when it has no broader.
            $broader = $entity->getBroader();
            $entity->setTop($broader ? $broader->getTop() : $entity);
        } elseif ($this->valueResource($entity, ['skos:topConceptOf'])) {
            // A concept declared as a top one is its own top concept. Only an
            // explicit value is used: the absence of a broader concept is not
            // enough, since the hierarchy may be stored in the broader concept.
            $entity->setTop($entity);
        }

        if ($this->shouldHydrate($request, 'o:position')) {
            $position = $request->getValue('o:position');
            $entity->setPosition($position === null || $position === '' ? null : (int) $position);
        }
    }

    /**
     * Get the first resource used as value for one of the properties.
     */
    protected function valueResource(EntityInterface $entity, array $terms): ?Resource
    {
        $propertyIds = array_flip($this->getServiceLocator()->get('Common\EasyMeta')->propertyIds($terms));
        if (!$propertyIds) {
            return null;
        }
        foreach ($entity->getValues() as $value) {
            $valueResource = $value->getValueResource();
            if ($valueResource && isset($propertyIds[$value->getProperty()->getId()])) {
                return $valueResource;
            }
        }
        return null;
    }

    public function validateEntity(EntityInterface $entity, ErrorStore $errorStore): void
    {
        parent::validateEntity($entity, $errorStore);

        /** @var \Thesaurus\Entity\Concept $entity */
        $scheme = $entity->getScheme();
        if (!$scheme) {
            $errorStore->addError('o:scheme', 'A concept must belong to a thesaurus scheme.'); // @translate
            return;
        }

        // The broader concept is already guaranteed to be a concept by the FK
        // and by findEntity() during hydration. Only the semantic consistency
        // is checked here: same scheme and no cycle.
        $broader = $entity->getBroader();
        if (!$broader) {
            return;
        }
        if ($broader->getScheme()->getId() !== $scheme->getId()) {
            $errorStore->addError('o:broader', 'The broader concept must belong to the same scheme.'); // @translate
        } elseif ($this->isSelfAncestor($entity, $broader)) {
            $errorStore->addError('o:broader', 'A concept cannot be an ancestor of itself.'); // @translate
        }
    }

    /**
     * Check that the concept is not one of the ancestors of its broader chain.
     */
    private function isSelfAncestor(EntityInterface $entity, ?Concept $broader): bool
    {
        $id = $entity->getId();
        for ($level = 0; $broader && $level < 100; ++$level) {
            if ($broader->getId() === $id) {
                return true;
            }
            $broader = $broader->getBroader();
        }
        return false;
    }
}
