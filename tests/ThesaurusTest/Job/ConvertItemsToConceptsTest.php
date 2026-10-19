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
     * A concept is not an item, so it is no more assigned to item sets, else
     * doctrine would load it as an item that does not exist.
     */
    public function testAssignmentsToItemSetsAreRemoved(): void
    {
        [$schemeId, $topId, $childId] = $this->createThesaurusOfItems();

        $itemSet = $this->api()->create('item_sets', [])->getContent();
        $this->createdResources[] = ['type' => 'item_sets', 'id' => $itemSet->id()];
        foreach ([$schemeId, $topId, $childId] as $id) {
            $this->connection()->executeStatement(
                'INSERT INTO `item_item_set` (`item_id`, `item_set_id`) VALUES (?, ?)',
                [$id, $itemSet->id()]
            );
        }

        $this->runJob(ConvertItemsToConcepts::class, ['scheme' => $schemeId]);

        $this->createdResources[] = ['type' => 'concepts', 'id' => $topId];
        $this->createdResources[] = ['type' => 'concepts', 'id' => $childId];

        $itemIds = $this->connection()
            ->executeQuery('SELECT `item_id` FROM `item_item_set` WHERE `item_set_id` = ?', [$itemSet->id()])
            ->fetchFirstColumn();
        $this->assertEquals([$schemeId], array_map('intval', $itemIds));
    }

    /**
     * The rows of the modules that reference the items via a foreign key are
     * removed too, for example the mapping features.
     */
    public function testReferencesOfModulesAreRemoved(): void
    {
        [$schemeId, $topId, $childId] = $this->createThesaurusOfItems();

        // The attachments of a block use the rule "set null".
        $site = $this->api()->create('sites', [
            'o:title' => 'ZZ site',
            'o:slug' => 'zz-site-' . uniqid(),
            'o:theme' => 'default',
        ])->getContent();
        $this->createdResources[] = ['type' => 'sites', 'id' => $site->id()];
        $page = $this->api()->create('site_pages', [
            'o:site' => ['o:id' => $site->id()],
            'o:title' => 'ZZ page',
            'o:slug' => 'zz-page',
            'o:block' => [[
                'o:layout' => 'media',
                'o:attachment' => [['o:item' => ['o:id' => $topId]]],
            ]],
        ])->getContent();
        $blockId = $page->blocks()[0]->id();

        $this->runJob(ConvertItemsToConcepts::class, ['scheme' => $schemeId]);

        $this->createdResources[] = ['type' => 'concepts', 'id' => $topId];
        $this->createdResources[] = ['type' => 'concepts', 'id' => $childId];

        $attachments = $this->connection()
            ->executeQuery('SELECT `item_id` FROM `site_block_attachment` WHERE `block_id` = ?', [$blockId])
            ->fetchFirstColumn();
        $this->assertEquals([null], $attachments);
    }

    /**
     * Create a custom vocab based on an item set, like a thesaurus does.
     */
    protected function createCustomVocab(string $label): int
    {
        if (!$this->getServiceLocator()->get('Omeka\ApiAdapterManager')->has('custom_vocabs')) {
            $this->markTestSkipped('Requires the module Custom Vocab.');
        }

        $itemSet = $this->api()->create('item_sets', [
            'dcterms:title' => [[
                'type' => 'literal',
                'property_id' => $this->propertyId('dcterms:title'),
                '@value' => $label,
            ]],
        ])->getContent();
        $this->createdResources[] = ['type' => 'item_sets', 'id' => $itemSet->id()];

        $customVocab = $this->api()->create('custom_vocabs', [
            'o:label' => $label,
            'o:item_set' => ['o:id' => $itemSet->id()],
        ])->getContent();

        return $customVocab->id();
    }

    /**
     * A value filled with a concept via a custom vocab uses the data type of
     * the thesaurus after the conversion, else it is refused on save.
     */
    public function testValuesOfCustomVocabUseTheThesaurus(): void
    {
        [$schemeId, $topId, $childId] = $this->createThesaurusOfItems();

        // The custom vocab of a thesaurus is based on its item set.
        $customVocabId = $this->createCustomVocab('ZZ vocab of thesaurus');

        $this->connection()->executeStatement(
            'INSERT INTO `value` (`resource_id`, `property_id`, `value_resource_id`, `type`, `is_public`) VALUES (?, ?, ?, ?, 1)',
            [$schemeId, $this->propertyId('dcterms:subject'), $topId, 'customvocab:' . $customVocabId]
        );

        $this->runJob(ConvertItemsToConcepts::class, ['scheme' => $schemeId]);

        $this->createdResources[] = ['type' => 'concepts', 'id' => $topId];
        $this->createdResources[] = ['type' => 'concepts', 'id' => $childId];

        $types = $this->connection()
            ->executeQuery('SELECT DISTINCT `type` FROM `value` WHERE `value_resource_id` = ? ORDER BY `type`', [$topId])
            ->fetchFirstColumn();
        $this->assertEquals(['resource:concept', 'thesaurus:' . $schemeId], $types);
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
