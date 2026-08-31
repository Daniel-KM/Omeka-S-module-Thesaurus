<?php declare(strict_types=1);

namespace Thesaurus\Api\Adapter;

use Doctrine\ORM\QueryBuilder;
use Omeka\Api\Adapter\AbstractResourceEntityAdapter;
use Omeka\Api\Request;
use Omeka\Entity\EntityInterface;
use Omeka\Stdlib\ErrorStore;
use Thesaurus\Api\Representation\ConceptRepresentation;
use Thesaurus\Entity\Concept;

class ConceptAdapter extends AbstractResourceEntityAdapter
{
    protected $sortFields = [
        'id' => 'id',
        'title' => 'title',
        'scheme_id' => 'scheme_id',
        'top_id' => 'top_id',
        'broader_id' => 'broader_id',
        'position' => 'position',
        'created' => 'created',
        'modified' => 'modified',
    ];

    protected $scalarFields = [
        'id' => 'id',
        'title' => 'title',
        'scheme_id' => 'scheme_id',
        'top_id' => 'top_id',
        'broader_id' => 'broader_id',
        'position' => 'position',
        'is_public' => 'isPublic',
        'created' => 'created',
        'modified' => 'modified',
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

        $expr = $qb->expr();

        if (isset($query['scheme_id']) && is_numeric($query['scheme_id'])) {
            $qb->andWhere($expr->eq(
                'omeka_root.scheme',
                $this->createNamedParameter($qb, (int) $query['scheme_id'])
            ));
        }

        if (isset($query['top_id']) && is_numeric($query['top_id'])) {
            $qb->andWhere($expr->eq(
                'omeka_root.top',
                $this->createNamedParameter($qb, (int) $query['top_id'])
            ));
        }

        if (array_key_exists('broader_id', $query)) {
            $qb->andWhere($query['broader_id'] === null || $query['broader_id'] === ''
                ? $expr->isNull('omeka_root.broader')
                : $expr->eq('omeka_root.broader', $this->createNamedParameter($qb, (int) $query['broader_id'])));
        }
    }

    public function hydrate(Request $request, EntityInterface $entity, ErrorStore $errorStore): void
    {
        parent::hydrate($request, $entity, $errorStore);

        $data = $request->getContent();

        if ($request->getOperation() === Request::CREATE && isset($data['o:scheme']['o:id'])) {
            $scheme = $this->getAdapter('items')->findEntity($data['o:scheme']['o:id']);
            $entity->setScheme($scheme);
        }

        if ($this->shouldHydrate($request, 'o:top')) {
            $topId = $request->getValue('o:top')['o:id'] ?? $request->getValue('o:top');
            $top = $topId ? $this->findEntity(['id' => $topId]) : null;
            $entity->setTop($top);
        }

        if ($this->shouldHydrate($request, 'o:broader')) {
            $broaderId = $request->getValue('o:broader')['o:id'] ?? $request->getValue('o:broader');
            $broader = $broaderId ? $this->findEntity(['id' => $broaderId]) : null;
            $entity->setBroader($broader);
        }

        if ($this->shouldHydrate($request, 'o:position')) {
            $position = $request->getValue('o:position');
            $entity->setPosition($position === null || $position === '' ? null : (int) $position);
        }
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
