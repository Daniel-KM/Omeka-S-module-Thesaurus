<?php declare(strict_types=1);

namespace ThesaurusTest\Job;

use CommonTest\AbstractHttpControllerTestCase;
use Thesaurus\Job\ConvertItemsToConcepts;
use ThesaurusTest\ThesaurusTestTrait;

/**
 * Tests for the conversion of a thesaurus built with items into concepts.
 */
class ConvertItemsToConceptsTest extends AbstractHttpControllerTestCase
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
     * Build a thesaurus made of items only, like before version 3.4.26.
     *
     * @return array Ids of the scheme and of the two concepts.
     */
    protected function createThesaurusOfItems(): array
    {
        $scheme = $this->createItem([
            'dcterms:title' => [['@value' => 'ZZ scheme']],
        ]);

        $top = $this->createItem([
            'skos:prefLabel' => [['@value' => 'ZZ racine']],
            'skos:topConceptOf' => [[
                'type' => 'resource:item',
                '@value' => null,
            ]],
        ]);
        $child = $this->createItem([
            'skos:prefLabel' => [['@value' => 'ZZ enfant']],
        ]);

        // The helper createItem() does not manage the resource values, so the
        // links are set directly with the api.
        $this->api()->update('items', $top->id(), [
            'skos:topConceptOf' => [[
                'type' => 'resource:item',
                'property_id' => $this->propertyId('skos:topConceptOf'),
                'value_resource_id' => $scheme->id(),
            ]],
        ], [], ['isPartial' => true]);
        $this->api()->update('items', $child->id(), [
            'skos:inScheme' => [[
                'type' => 'resource:item',
                'property_id' => $this->propertyId('skos:inScheme'),
                'value_resource_id' => $scheme->id(),
            ]],
            'skos:broader' => [[
                'type' => 'resource:item',
                'property_id' => $this->propertyId('skos:broader'),
                'value_resource_id' => $top->id(),
            ]],
        ], [], ['isPartial' => true]);

        return [$scheme->id(), $top->id(), $child->id()];
    }

    protected function resourceType(int $id): ?string
    {
        return $this->connection()
            ->executeQuery('SELECT `resource_type` FROM `resource` WHERE `id` = ?', [$id])
            ->fetchOne() ?: null;
    }

    public function testItemsAreConvertedIntoConcepts(): void
    {
        [$schemeId, $topId, $childId] = $this->createThesaurusOfItems();

        $this->runJob(ConvertItemsToConcepts::class, ['scheme' => $schemeId]);

        // The resources are no longer items, so they are cleaned as concepts.
        $this->createdResources[] = ['type' => 'concepts', 'id' => $topId];
        $this->createdResources[] = ['type' => 'concepts', 'id' => $childId];

        // The scheme remains an item, the concepts are a new resource type.
        $this->assertEquals('Omeka\Entity\Item', $this->resourceType($schemeId));
        $this->assertEquals('Thesaurus\Entity\Concept', $this->resourceType($topId));
        $this->assertEquals('Thesaurus\Entity\Concept', $this->resourceType($childId));

        // The rows of the table "item" are removed, so the concepts are no
        // longer items.
        $items = $this->connection()
            ->executeQuery('SELECT `id` FROM `item` WHERE `id` IN (?, ?, ?)', [$schemeId, $topId, $childId])
            ->fetchFirstColumn();
        $this->assertEquals([$schemeId], array_map('intval', $items));

        // The scheme of the concepts is set, the tree is built by the job of
        // indexation.
        $schemes = $this->connection()
            ->executeQuery('SELECT DISTINCT `scheme_id` FROM `concept` WHERE `id` IN (?, ?)', [$topId, $childId])
            ->fetchFirstColumn();
        $this->assertEquals([$schemeId], array_map('intval', $schemes));

        // The values pointing to a concept use the new data type.
        $types = $this->connection()
            ->executeQuery('SELECT DISTINCT `type` FROM `value` WHERE `value_resource_id` = ?', [$topId])
            ->fetchFirstColumn();
        $this->assertEquals(['resource:concept'], $types);

        // The concepts are readable with their own api. The identity map is
        // cleared first, because the job changed the type of the resources with
        // sql: a new request would load them as concepts.
        $this->getEntityManager()->clear();
        $concept = $this->api()->read('concepts', $childId)->getContent();
        $this->assertEquals($schemeId, $concept->scheme()->id());
    }

    /**
     * A concept has no media, so a thesaurus with files is not converted at
     * all, else it would be partly converted and no more consistent.
     */
    public function testThesaurusWithMediaIsNotConverted(): void
    {
        [$schemeId, $topId, $childId] = $this->createThesaurusOfItems();

        // A media is added directly, because the ingest of a file is useless
        // here: only the presence of a row is checked by the job.
        $this->connection()->executeStatement(
            'INSERT INTO `resource` (`is_public`, `created`, `resource_type`) VALUES (1, NOW(), ?)',
            ['Omeka\Entity\Media']
        );
        $mediaId = (int) $this->connection()->lastInsertId();
        $this->connection()->executeStatement(
            'INSERT INTO `media` (`id`, `item_id`, `ingester`, `renderer`, `has_original`, `has_thumbnails`, `position`) VALUES (?, ?, ?, ?, 0, 0, 1)',
            [$mediaId, $childId, 'upload', 'file']
        );

        $this->runJob(ConvertItemsToConcepts::class, ['scheme' => $schemeId]);

        // Nothing is converted, not even the concept without media.
        $this->assertEquals('Omeka\Entity\Item', $this->resourceType($topId));
        $this->assertEquals('Omeka\Entity\Item', $this->resourceType($childId));
        $total = (int) $this->connection()
            ->executeQuery('SELECT COUNT(*) FROM `concept` WHERE `id` IN (?, ?)', [$topId, $childId])
            ->fetchOne();
        $this->assertEquals(0, $total);

        $this->connection()->executeStatement('DELETE FROM `media` WHERE `id` = ?', [$mediaId]);
        $this->connection()->executeStatement('DELETE FROM `resource` WHERE `id` = ?', [$mediaId]);
    }
}
