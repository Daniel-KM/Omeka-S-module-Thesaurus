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
        'root_id' => 'root_id',
        'broader_id' => 'broader_id',
        'position' => 'position',
        'created' => 'created',
        'modified' => 'modified',
    ];

    protected $scalarFields = [
        'id' => 'id',
        'title' => 'title',
        'scheme_id' => 'scheme_id',
        'root_id' => 'root_id',
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

        if (isset($query['root_id']) && is_numeric($query['root_id'])) {
            $qb->andWhere($expr->eq(
                'omeka_root.root',
                $this->createNamedParameter($qb, (int) $query['root_id'])
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

        if ($this->shouldHydrate($request, 'o:root')) {
            $rootId = $request->getValue('o:root')['o:id'] ?? $request->getValue('o:root');
            $root = $rootId ? $this->findEntity(['id' => $rootId]) : null;
            $entity->setRoot($root);
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

        if (!$entity->getScheme()) {
            $errorStore->addError('o:scheme', 'A concept must belong to a thesaurus scheme.'); // @translate
        }
    }
}
