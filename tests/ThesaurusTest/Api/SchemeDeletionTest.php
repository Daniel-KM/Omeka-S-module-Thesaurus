<?php declare(strict_types=1);

namespace ThesaurusTest\Api;

use CommonTest\AbstractHttpControllerTestCase;
use ThesaurusTest\ThesaurusTestTrait;

/**
 * Tests the deletion of a scheme, that must delete its concepts entirely.
 *
 * The foreign key of the column "scheme_id" removes the rows of the table
 * "concept" by cascade, but not the rows of the table "resource": without the
 * listener of the module, the resources would remain without their subtype row
 * and any query on resources would fail.
 */
class SchemeDeletionTest extends AbstractHttpControllerTestCase
{
    use ThesaurusTestTrait;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
    }

    public function tearDown(): void
    {
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

    /**
     * Create a scheme with a top concept and a narrower one.
     *
     * @return array Ids of the scheme, the top concept and the narrower one.
     */
    protected function createThesaurusOfConcepts(string $name): array
    {
        $scheme = $this->createItem([
            'dcterms:title' => [['@value' => $name]],
        ]);

        $top = $this->api()->create('concepts', [
            'skos:prefLabel' => [[
                'type' => 'literal',
                'property_id' => $this->propertyId('skos:prefLabel'),
                '@value' => $name . ' top',
            ]],
            'skos:topConceptOf' => [[
                'type' => 'resource:item',
                'property_id' => $this->propertyId('skos:topConceptOf'),
                'value_resource_id' => $scheme->id(),
            ]],
        ])->getContent();

        $child = $this->api()->create('concepts', [
            'skos:prefLabel' => [[
                'type' => 'literal',
                'property_id' => $this->propertyId('skos:prefLabel'),
                '@value' => $name . ' child',
            ]],
            'skos:inScheme' => [[
                'type' => 'resource:item',
                'property_id' => $this->propertyId('skos:inScheme'),
                'value_resource_id' => $scheme->id(),
            ]],
            'skos:broader' => [[
                'type' => 'resource:concept',
                'property_id' => $this->propertyId('skos:broader'),
                'value_resource_id' => $top->id(),
            ]],
        ])->getContent();

        return [$scheme->id(), $top->id(), $child->id()];
    }

    protected function totalResources(array $ids): int
    {
        return (int) $this->connection()
            ->executeQuery(
                'SELECT COUNT(*) FROM `resource` WHERE `id` IN (?)',
                [$ids],
                [\Doctrine\DBAL\Connection::PARAM_INT_ARRAY]
            )
            ->fetchOne();
    }

    protected function totalConcepts(array $ids): int
    {
        return (int) $this->connection()
            ->executeQuery(
                'SELECT COUNT(*) FROM `concept` WHERE `id` IN (?)',
                [$ids],
                [\Doctrine\DBAL\Connection::PARAM_INT_ARRAY]
            )
            ->fetchOne();
    }

    /**
     * The deletion from the admin interface uses the api, so the concepts and
     * their resources are removed, with their values.
     */
    public function testDeleteSchemeDeletesItsConcepts(): void
    {
        [$schemeId, $topId, $childId] = $this->createThesaurusOfConcepts('ZZ suppression');

        $this->assertEquals(2, $this->totalConcepts([$topId, $childId]));

        $this->api()->delete('items', $schemeId);

        $this->assertEquals(0, $this->totalConcepts([$topId, $childId]));
        $this->assertEquals(0, $this->totalResources([$topId, $childId]));

        // The values of the concepts and the values that point to them are
        // removed too.
        $totalValues = (int) $this->connection()
            ->executeQuery(
                'SELECT COUNT(*) FROM `value` WHERE `resource_id` IN (?) OR `value_resource_id` IN (?)',
                [[$topId, $childId], [$topId, $childId]],
                [\Doctrine\DBAL\Connection::PARAM_INT_ARRAY, \Doctrine\DBAL\Connection::PARAM_INT_ARRAY]
            )
            ->fetchOne();
        $this->assertEquals(0, $totalValues);
    }

    /**
     * The batch deletion of the admin interface deletes each resource with the
     * api too, so the concepts follow their scheme.
     */
    public function testBatchDeleteSchemesDeletesTheirConcepts(): void
    {
        [$schemeA, $topA, $childA] = $this->createThesaurusOfConcepts('ZZ batch A');
        [$schemeB, $topB, $childB] = $this->createThesaurusOfConcepts('ZZ batch B');
        $other = $this->createItem([
            'dcterms:title' => [['@value' => 'ZZ item ordinaire']],
        ]);

        $this->api()->batchDelete('items', [$schemeA, $schemeB, $other->id()]);

        $this->assertEquals(0, $this->totalConcepts([$topA, $childA, $topB, $childB]));
        $this->assertEquals(0, $this->totalResources([$topA, $childA, $topB, $childB]));
        $this->assertEquals(0, $this->totalResources([$schemeA, $schemeB, $other->id()]));
    }

    /**
     * The concepts are converted into items when the sidebar of confirmation
     * asks it, so the resources and their values are kept.
     */
    public function testDeleteSchemeCanConvertItsConcepts(): void
    {
        [$schemeId, $topId, $childId] = $this->createThesaurusOfConcepts('ZZ conversion');

        $this->getRequest()->setPost(new \Laminas\Stdlib\Parameters([
            'thesaurus-scheme-delete-mode' => 'convert',
        ]));
        $this->api()->delete('items', $schemeId);

        // The rows of the table "concept" are removed, but the resources are
        // kept as items.
        $this->assertEquals(0, $this->totalConcepts([$topId, $childId]));
        $this->assertEquals(2, $this->totalResources([$topId, $childId]));

        $types = $this->connection()
            ->executeQuery(
                'SELECT DISTINCT `resource_type` FROM `resource` WHERE `id` IN (?)',
                [[$topId, $childId]],
                [\Doctrine\DBAL\Connection::PARAM_INT_ARRAY]
            )
            ->fetchFirstColumn();
        $this->assertEquals(['Omeka\Entity\Item'], $types);

        $items = $this->connection()
            ->executeQuery(
                'SELECT COUNT(*) FROM `item` WHERE `id` IN (?)',
                [[$topId, $childId]],
                [\Doctrine\DBAL\Connection::PARAM_INT_ARRAY]
            )
            ->fetchOne();
        $this->assertEquals(2, (int) $items);

        // The values that point to the concepts use the data type of the items.
        $types = $this->connection()
            ->executeQuery(
                'SELECT DISTINCT `type` FROM `value` WHERE `value_resource_id` IN (?)',
                [[$topId, $childId]],
                [\Doctrine\DBAL\Connection::PARAM_INT_ARRAY]
            )
            ->fetchFirstColumn();
        $this->assertEquals(['resource:item'], $types);

        // Clean up: they are items now.
        $this->createdResources[] = ['type' => 'items', 'id' => $topId];
        $this->createdResources[] = ['type' => 'items', 'id' => $childId];
    }

    /**
     * The deletion of an item that is not a scheme does not delete anything
     * else.
     */
    public function testDeleteItemKeepsConcepts(): void
    {
        [$schemeId, $topId, $childId] = $this->createThesaurusOfConcepts('ZZ intact');
        $other = $this->createItem([
            'dcterms:title' => [['@value' => 'ZZ autre item']],
        ]);

        $this->api()->delete('items', $other->id());

        $this->assertEquals(2, $this->totalConcepts([$topId, $childId]));
        $this->assertEquals(1, $this->totalResources([$schemeId]));

        // Clean up: the concepts are removed with their scheme.
        $this->api()->delete('items', $schemeId);
    }
}
