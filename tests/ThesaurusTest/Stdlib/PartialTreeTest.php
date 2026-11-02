<?php declare(strict_types=1);

namespace ThesaurusTest\Stdlib;

use CommonTest\AbstractHttpControllerTestCase;
use Thesaurus\Job\ConvertItemsToConcepts;
use ThesaurusTest\ThesaurusTestTrait;

/**
 * Tests the methods that use a partial tree instead of the whole thesaurus.
 *
 * The descendants, the ascendants, the broader and the narrower concepts are
 * read from the hierarchy of the item only, so the results must be the same
 * than the ones of the whole tree.
 */
class PartialTreeTest extends AbstractHttpControllerTestCase
{
    use ThesaurusTestTrait;

    const METHODS = [
        'descendants',
        'descendantsOrSelf',
        'ascendants',
        'ascendantsOrSelf',
        'broader',
        'broaderOrSelf',
        'narrowers',
        'narrowersOrSelf',
        'top',
    ];

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
    }

    public function tearDown(): void
    {
        $this->cleanupBackups();
        $this->cleanupResources();
        parent::tearDown();
    }

    protected function propertyId(string $term): int
    {
        return (int) $this->getServiceLocator()->get('Common\EasyMeta')->propertyId($term);
    }

    protected function connection(): \Doctrine\DBAL\Connection
    {
        return $this->getServiceLocator()->get('Omeka\Connection');
    }

    protected function thesaurus(): \Thesaurus\Stdlib\Thesaurus
    {
        return $this->getServiceLocator()->build('Thesaurus\Thesaurus');
    }

    /**
     * Reload the resources modified with sql, like the converted concepts.
     */
    protected function refreshResources(array $ids): void
    {
        $entityManager = $this->getServiceLocator()->get('Omeka\EntityManager');
        foreach ($ids as $id) {
            $entity = $entityManager->find(\Omeka\Entity\Resource::class, $id);
            if ($entity) {
                $entityManager->detach($entity);
            }
        }
    }

    /**
     * Add a value via the api, so the entities in memory are up to date.
     */
    protected function addValue(int $resourceId, string $term, int $valueResourceId): void
    {
        $this->api()->update('items', $resourceId, [
            $term => [[
                'type' => 'resource:item',
                'property_id' => $this->propertyId($term),
                'value_resource_id' => $valueResourceId,
            ]],
        ], [], ['isPartial' => true, 'collectionAction' => 'append']);
    }

    /**
     * Build a thesaurus of items: two tops, children and grandchildren, with
     * relations in both directions.
     *
     * @return array Ids of the scheme and of all the concepts.
     */
    protected function createThesaurusOfItems(): array
    {
        $scheme = $this->createItem(['dcterms:title' => [['@value' => 'ZZ scheme']]]);
        $schemeId = (int) $scheme->id();
        $ids = [];
        foreach (['A', 'B'] as $topLabel) {
            $top = (int) $this->createItem(['skos:prefLabel' => [['@value' => "ZZ $topLabel"]]])->id();
            $this->addValue($top, 'skos:topConceptOf', $schemeId);
            $this->addValue($schemeId, 'skos:hasTopConcept', $top);
            $ids[] = $top;
            foreach ([1, 2, 3] as $childLabel) {
                $child = (int) $this->createItem(['skos:prefLabel' => [['@value' => "ZZ $topLabel$childLabel"]]])->id();
                $this->addValue($child, 'skos:inScheme', $schemeId);
                $this->addValue($child, 'skos:broader', $top);
                $this->addValue($top, 'skos:narrower', $child);
                $ids[] = $child;
                foreach (['x', 'y'] as $leafLabel) {
                    $leaf = (int) $this->createItem(['skos:prefLabel' => [['@value' => "ZZ $topLabel$childLabel$leafLabel"]]])->id();
                    $this->addValue($leaf, 'skos:inScheme', $schemeId);
                    $this->addValue($leaf, 'skos:broader', $child);
                    $this->addValue($child, 'skos:narrower', $leaf);
                    $ids[] = $leaf;
                }
            }
        }
        return [$schemeId, $ids];
    }

    /**
     * Check that the partial tree gives the results of the whole tree.
     */
    protected function assertSameAsWholeTree(array $ids): void
    {
        $api = $this->api();
        foreach ($ids as $id) {
            $resource = $api->read('resources', ['id' => $id])->getContent();
            // A new instance, like a consumer, with the whole tree.
            $whole = $this->thesaurus();
            $whole($resource)->flatTree();
            foreach (self::METHODS as $method) {
                $partial = $this->thesaurus();
                $this->assertSame(
                    json_encode($whole->$method()),
                    json_encode($partial($resource)->$method()),
                    "Method $method differs for #$id."
                );
            }
            foreach ([true, false] as $fromTop) {
                $partial = $this->thesaurus();
                $this->assertSame(json_encode($whole->ascendants($fromTop)), json_encode($partial($resource)->ascendants($fromTop)));
            }
        }
    }

    public function testPartialTreeOfItems(): void
    {
        [, $ids] = $this->createThesaurusOfItems();
        $this->assertSameAsWholeTree($ids);
    }

    public function testPartialTreeOfConcepts(): void
    {
        [$schemeId, $ids] = $this->createThesaurusOfItems();
        $this->runJob(ConvertItemsToConcepts::class, ['schemes' => [$schemeId]]);
        $this->refreshResources($ids);
        $this->assertSameAsWholeTree($ids);
    }

    /**
     * The whole tree is not built to get the descendants or the ascendants.
     */
    public function testWholeTreeIsNotBuilt(): void
    {
        [, $ids] = $this->createThesaurusOfItems();
        $thesaurus = $this->thesaurus();
        $thesaurus($ids[1]);
        $thesaurus->descendants();
        $thesaurus->ascendantsOrSelf(true);
        $thesaurus->narrowers();

        $property = new \ReflectionProperty($thesaurus, 'structureBuilt');
        $property->setAccessible(true);
        $this->assertFalse($property->getValue($thesaurus), 'The whole tree was built.');

        $thesaurus->flatTree();
        $this->assertTrue($property->getValue($thesaurus));
    }

    /**
     * The whole tree is built when the instance is used for many concepts of
     * the same thesaurus, so it is kept for the next calls.
     */
    public function testWholeTreeIsBuiltAfterManyCalls(): void
    {
        [, $ids] = $this->createThesaurusOfItems();
        $thesaurus = $this->thesaurus();
        $property = new \ReflectionProperty($thesaurus, 'structureBuilt');
        $property->setAccessible(true);

        $calls = 0;
        foreach ($ids as $id) {
            $thesaurus($id)->descendants();
            ++$calls;
            if ($calls <= \Thesaurus\Stdlib\Thesaurus::PARTIAL_LIMIT) {
                $this->assertFalse($property->getValue($thesaurus), "The whole tree was built after $calls calls.");
            }
        }
        $this->assertGreaterThan(\Thesaurus\Stdlib\Thesaurus::PARTIAL_LIMIT, $calls);
        $this->assertTrue($property->getValue($thesaurus), 'The whole tree was not built after many calls.');
    }

    /**
     * The order of the children follows the values, even when they are
     * duplicated.
     */
    public function testOrderOfChildrenWithDuplicatedValues(): void
    {
        [, $ids] = $this->createThesaurusOfItems();
        $top = $ids[0];
        $children = [$ids[1], $ids[4], $ids[7]];
        foreach ($children as $child) {
            $this->addValue($top, 'skos:narrower', $child);
        }

        // The narrower concepts are a list, not indexed by id.
        $narrowers = $this->thesaurus()($top)->narrowers();
        $this->assertSame($children, array_column($narrowers, 'id'));
        $thesaurus = $this->thesaurus();
        $thesaurus($top)->flatTree();
        $this->assertSame($children, array_column($thesaurus->narrowers(), 'id'));
    }

    /**
     * A concept related to its thesaurus via "skos:broader" only is found.
     */
    public function testConceptWithoutInScheme(): void
    {
        [$schemeId, $ids] = $this->createThesaurusOfItems();
        $this->runJob(ConvertItemsToConcepts::class, ['schemes' => [$schemeId]]);
        $this->refreshResources($ids);

        $leaf = $ids[2];
        $this->connection()->executeStatement(
            'DELETE FROM `value` WHERE `resource_id` = ? AND `property_id` = ?',
            [$leaf, $this->propertyId('skos:inScheme')]
        );
        $this->refreshResources([$leaf]);
        $concept = $this->api()->read('concepts', $leaf)->getContent();

        $thesaurus = $this->thesaurus();
        $thesaurus($concept);
        $this->assertNotNull($thesaurus->scheme());
        $this->assertSame($schemeId, $thesaurus->scheme()->id());
        $this->assertSame([$ids[1], $ids[0]], array_keys($thesaurus->ascendants()));
    }
}
