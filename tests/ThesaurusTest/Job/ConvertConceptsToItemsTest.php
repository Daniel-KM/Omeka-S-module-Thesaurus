<?php declare(strict_types=1);

namespace ThesaurusTest\Job;

use CommonTest\AbstractHttpControllerTestCase;
use Thesaurus\Job\ConvertConceptsToItems;
use Thesaurus\Job\ConvertItemsToConcepts;
use ThesaurusTest\ThesaurusTestTrait;

/**
 * Tests the conversion of the concepts of a thesaurus back into items.
 */
class ConvertConceptsToItemsTest extends AbstractHttpControllerTestCase
{
    use ThesaurusTestTrait;

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

    protected function resourceType(int $id): ?string
    {
        return $this->connection()
            ->executeQuery('SELECT `resource_type` FROM `resource` WHERE `id` = ?', [$id])
            ->fetchOne() ?: null;
    }

    protected function isItem(int $id): bool
    {
        return (bool) (int) $this->connection()
            ->executeQuery('SELECT COUNT(*) FROM `item` WHERE `id` = ?', [$id])
            ->fetchOne();
    }

    protected function isConcept(int $id): bool
    {
        return (bool) (int) $this->connection()
            ->executeQuery('SELECT COUNT(*) FROM `concept` WHERE `id` = ?', [$id])
            ->fetchOne();
    }

    protected function valueType(int $valueId): ?string
    {
        return $this->connection()
            ->executeQuery('SELECT `type` FROM `value` WHERE `id` = ?', [$valueId])
            ->fetchOne() ?: null;
    }

    /**
     * Build a thesaurus of items and convert it into concepts.
     *
     * @return array Ids of the scheme, of the top concept and of the child.
     */
    protected function createThesaurusOfConcepts(string $label = 'ZZ scheme'): array
    {
        $scheme = $this->createItem(['dcterms:title' => [['@value' => $label]]]);
        $top = $this->createItem(['skos:prefLabel' => [['@value' => $label . ' racine']]]);
        $child = $this->createItem(['skos:prefLabel' => [['@value' => $label . ' enfant']]]);

        $this->connection()->executeStatement(
            'INSERT INTO `value` (`resource_id`, `property_id`, `value_resource_id`, `type`, `is_public`) VALUES (?, ?, ?, ?, 1)',
            [$top->id(), $this->propertyId('skos:topConceptOf'), $scheme->id(), 'resource:item']
        );
        $this->connection()->executeStatement(
            'INSERT INTO `value` (`resource_id`, `property_id`, `value_resource_id`, `type`, `is_public`) VALUES (?, ?, ?, ?, 1)',
            [$child->id(), $this->propertyId('skos:broader'), $top->id(), 'resource:item']
        );

        $this->runJob(ConvertItemsToConcepts::class, ['schemes' => [$scheme->id()]]);

        return [(int) $scheme->id(), (int) $top->id(), (int) $child->id()];
    }

    /**
     * Without selected thesaurus, the task does not change any data.
     */
    public function testNoSelectionDoesNothing(): void
    {
        [, $topId] = $this->createThesaurusOfConcepts();

        $this->runJob(ConvertConceptsToItems::class, []);

        $this->assertTrue($this->isConcept($topId));
        $this->assertFalse($this->isItem($topId));
    }

    /**
     * The concepts become items again and keep their id.
     */
    public function testConceptsAreConvertedIntoItems(): void
    {
        [$schemeId, $topId, $childId] = $this->createThesaurusOfConcepts();

        $this->runJob(ConvertConceptsToItems::class, ['schemes' => [$schemeId]]);

        foreach ([$topId, $childId] as $id) {
            $this->assertEquals('Omeka\Entity\Item', $this->resourceType($id));
            $this->assertTrue($this->isItem($id), "The concept #$id is not an item again.");
            $this->assertFalse($this->isConcept($id), "The concept #$id is still a concept.");
        }
        $this->assertEquals('Omeka\Entity\Item', $this->resourceType($schemeId));
    }

    /**
     * The values pointing to the concepts use the data type of the items.
     */
    public function testValuesUseItemDataType(): void
    {
        [$schemeId, $topId, $childId] = $this->createThesaurusOfConcepts();

        $item = $this->createItem(['dcterms:title' => [['@value' => 'ZZ item']]]);
        $this->connection()->executeStatement(
            'INSERT INTO `value` (`resource_id`, `property_id`, `value_resource_id`, `type`, `is_public`) VALUES (?, ?, ?, ?, 1)',
            [$item->id(), $this->propertyId('dcterms:subject'), $childId, 'thesaurus:' . $schemeId]
        );
        $valueId = (int) $this->connection()->lastInsertId();

        $broaderId = (int) $this->connection()
            ->executeQuery(
                'SELECT `id` FROM `value` WHERE `resource_id` = ? AND `value_resource_id` = ?',
                [$childId, $topId]
            )
            ->fetchOne();
        $this->assertEquals('resource:concept', $this->valueType($broaderId));

        $this->runJob(ConvertConceptsToItems::class, ['schemes' => [$schemeId]]);

        $this->assertEquals('resource:item', $this->valueType($valueId));
        $this->assertEquals('resource:item', $this->valueType($broaderId));
    }

    /**
     * Only the concepts of the selected thesaurus are converted.
     */
    public function testOnlySelectedThesaurusIsConverted(): void
    {
        [$schemeId, $topId] = $this->createThesaurusOfConcepts('ZZ scheme');
        [, $otherTopId] = $this->createThesaurusOfConcepts('ZZ other scheme');

        $this->runJob(ConvertConceptsToItems::class, ['schemes' => [$schemeId]]);

        $this->assertTrue($this->isItem($topId));
        $this->assertTrue($this->isConcept($otherTopId));
    }

    /**
     * The assignments removed by the conversion are saved and restored.
     */
    public function testAssignmentsAreSavedAndRestored(): void
    {
        $services = $this->getServiceLocator();
        $connection = $this->connection();

        $itemSet = $this->api()->create('item_sets', [
            'dcterms:title' => [[
                'type' => 'literal',
                'property_id' => $this->propertyId('dcterms:title'),
                '@value' => 'ZZ item set',
            ]],
        ])->getContent();
        $this->createdResources[] = ['type' => 'item_sets', 'id' => $itemSet->id()];

        $scheme = $this->createItem(['dcterms:title' => [['@value' => 'ZZ scheme']]]);
        $top = $this->createItem(['skos:prefLabel' => [['@value' => 'ZZ racine']]]);
        $connection->executeStatement(
            'INSERT INTO `value` (`resource_id`, `property_id`, `value_resource_id`, `type`, `is_public`) VALUES (?, ?, ?, ?, 1)',
            [$top->id(), $this->propertyId('skos:topConceptOf'), $scheme->id(), 'resource:item']
        );
        $connection->executeStatement(
            'INSERT INTO `item_item_set` (`item_id`, `item_set_id`) VALUES (?, ?)',
            [$top->id(), $itemSet->id()]
        );

        $countAssignments = fn () => (int) $connection
            ->executeQuery('SELECT COUNT(*) FROM `item_item_set` WHERE `item_id` = ?', [$top->id()])
            ->fetchOne();
        $this->assertSame(1, $countAssignments());

        $this->runJob(ConvertItemsToConcepts::class, ['schemes' => [$scheme->id()]]);

        // A concept is not an item, so the assignment was removed and saved.
        $this->assertSame(0, $countAssignments());
        $backup = new \Thesaurus\Stdlib\ReferencesBackup($connection);
        $content = $backup->readLast([(int) $scheme->id()]);
        $this->assertNotNull($content, 'The removed rows were not saved.');
        $this->assertArrayHasKey('item_item_set', $content['tables']);

        $this->runJob(ConvertConceptsToItems::class, ['schemes' => [$scheme->id()]]);

        $this->assertSame(1, $countAssignments(), 'The assignment was not restored.');

    }

    /**
     * The data type of a value filled via a custom vocab is restored as is.
     */
    public function testCustomVocabTypeIsRestored(): void
    {
        $connection = $this->connection();

        $scheme = $this->createItem(['dcterms:title' => [['@value' => 'ZZ scheme']]]);
        $top = $this->createItem(['skos:prefLabel' => [['@value' => 'ZZ racine']]]);
        $connection->executeStatement(
            'INSERT INTO `value` (`resource_id`, `property_id`, `value_resource_id`, `type`, `is_public`) VALUES (?, ?, ?, ?, 1)',
            [$top->id(), $this->propertyId('skos:topConceptOf'), $scheme->id(), 'resource:item']
        );

        $item = $this->createItem(['dcterms:title' => [['@value' => 'ZZ item']]]);
        $connection->executeStatement(
            'INSERT INTO `value` (`resource_id`, `property_id`, `value_resource_id`, `type`, `is_public`) VALUES (?, ?, ?, ?, 1)',
            [$item->id(), $this->propertyId('dcterms:subject'), $top->id(), 'customvocab:3']
        );
        $valueId = (int) $connection->lastInsertId();

        $this->runJob(ConvertItemsToConcepts::class, ['schemes' => [$scheme->id()]]);
        $this->assertEquals('thesaurus:' . $scheme->id(), $this->valueType($valueId));

        $this->runJob(ConvertConceptsToItems::class, ['schemes' => [$scheme->id()]]);

        // Without the saved types, the value would get "resource:item".
        $this->assertEquals('customvocab:3', $this->valueType($valueId));

    }

    /**
     * Without backup, the thesaurus is not converted at all.
     */
    public function testNoConversionWithoutBackup(): void
    {
        $connection = $this->connection();

        $scheme = $this->createItem(['dcterms:title' => [['@value' => 'ZZ scheme']]]);
        $top = $this->createItem(['skos:prefLabel' => [['@value' => 'ZZ racine']]]);
        $connection->executeStatement(
            'INSERT INTO `value` (`resource_id`, `property_id`, `value_resource_id`, `type`, `is_public`) VALUES (?, ?, ?, ?, 1)',
            [$top->id(), $this->propertyId('skos:topConceptOf'), $scheme->id(), 'resource:item']
        );

        // An assignment that would be lost by the conversion.
        $itemSet = $this->api()->create('item_sets', [
            'dcterms:title' => [[
                'type' => 'literal',
                'property_id' => $this->propertyId('dcterms:title'),
                '@value' => 'ZZ item set',
            ]],
        ])->getContent();
        $this->createdResources[] = ['type' => 'item_sets', 'id' => $itemSet->id()];
        $connection->executeStatement(
            'INSERT INTO `item_item_set` (`item_id`, `item_set_id`) VALUES (?, ?)',
            [$top->id(), $itemSet->id()]
        );

        // The table of the backups is unavailable.
        $connection->executeStatement('RENAME TABLE `thesaurus_backup` TO `thesaurus_backup_hidden`');
        try {
            $this->runJob(ConvertItemsToConcepts::class, ['schemes' => [$scheme->id()]]);
        } finally {
            $connection->executeStatement('RENAME TABLE `thesaurus_backup_hidden` TO `thesaurus_backup`');
        }

        $this->assertTrue($this->isItem((int) $top->id()), 'The item was converted without backup.');
        $this->assertFalse($this->isConcept((int) $top->id()));
        $this->assertSame(1, (int) $connection
            ->executeQuery('SELECT COUNT(*) FROM `item_item_set` WHERE `item_id` = ?', [$top->id()])
            ->fetchOne(), 'The assignment was removed without backup.');
    }

    /**
     * The data types changed since the conversion are kept by the rollback.
     */
    public function testChangedTypeIsKeptByRollback(): void
    {
        $connection = $this->connection();

        $scheme = $this->createItem(['dcterms:title' => [['@value' => 'ZZ scheme']]]);
        $top = $this->createItem(['skos:prefLabel' => [['@value' => 'ZZ racine']]]);
        $connection->executeStatement(
            'INSERT INTO `value` (`resource_id`, `property_id`, `value_resource_id`, `type`, `is_public`) VALUES (?, ?, ?, ?, 1)',
            [$top->id(), $this->propertyId('skos:topConceptOf'), $scheme->id(), 'resource:item']
        );
        $item = $this->createItem(['dcterms:title' => [['@value' => 'ZZ item']]]);
        $connection->executeStatement(
            'INSERT INTO `value` (`resource_id`, `property_id`, `value_resource_id`, `type`, `is_public`) VALUES (?, ?, ?, ?, 1)',
            [$item->id(), $this->propertyId('dcterms:subject'), $top->id(), 'customvocab:3']
        );
        $keptId = (int) $connection->lastInsertId();
        $connection->executeStatement(
            'INSERT INTO `value` (`resource_id`, `property_id`, `value_resource_id`, `type`, `is_public`) VALUES (?, ?, ?, ?, 1)',
            [$item->id(), $this->propertyId('dcterms:subject'), $top->id(), 'customvocab:3']
        );
        $changedId = (int) $connection->lastInsertId();

        $this->runJob(ConvertItemsToConcepts::class, ['schemes' => [$scheme->id()]]);

        // A value is updated manually after the conversion.
        $connection->executeStatement('UPDATE `value` SET `type` = ? WHERE `id` = ?', ['customvocab:7', $changedId]);

        $this->runJob(ConvertConceptsToItems::class, ['schemes' => [$scheme->id()]]);

        $this->assertEquals('customvocab:3', $this->valueType($keptId), 'The saved type was not restored.');
        $this->assertEquals('customvocab:7', $this->valueType($changedId), 'The current type was overwritten by the backup.');
    }

    /**
     * The restoration of the assignments can be skipped.
     */
    public function testRestorationCanBeSkipped(): void
    {
        $connection = $this->connection();

        $itemSet = $this->api()->create('item_sets', [
            'dcterms:title' => [[
                'type' => 'literal',
                'property_id' => $this->propertyId('dcterms:title'),
                '@value' => 'ZZ item set',
            ]],
        ])->getContent();
        $this->createdResources[] = ['type' => 'item_sets', 'id' => $itemSet->id()];

        $scheme = $this->createItem(['dcterms:title' => [['@value' => 'ZZ scheme']]]);
        $top = $this->createItem(['skos:prefLabel' => [['@value' => 'ZZ racine']]]);
        $connection->executeStatement(
            'INSERT INTO `value` (`resource_id`, `property_id`, `value_resource_id`, `type`, `is_public`) VALUES (?, ?, ?, ?, 1)',
            [$top->id(), $this->propertyId('skos:topConceptOf'), $scheme->id(), 'resource:item']
        );
        $connection->executeStatement(
            'INSERT INTO `item_item_set` (`item_id`, `item_set_id`) VALUES (?, ?)',
            [$top->id(), $itemSet->id()]
        );

        $this->runJob(ConvertItemsToConcepts::class, ['schemes' => [$scheme->id()]]);
        $this->runJob(ConvertConceptsToItems::class, ['schemes' => [$scheme->id()], 'restore' => false]);

        $this->assertSame(0, (int) $connection
            ->executeQuery('SELECT COUNT(*) FROM `item_item_set` WHERE `item_id` = ?', [$top->id()])
            ->fetchOne());
    }

    /**
     * The items restored by the rollback can be saved again.
     *
     * The templates of the thesaurus may have been reserved to the concepts by
     * the conversion, so they must accept the items again.
     */
    public function testRestoredItemsCanBeSaved(): void
    {
        $connection = $this->connection();
        $templateId = (int) $connection
            ->executeQuery("SELECT `id` FROM `resource_template` WHERE `label` = 'Thesaurus Concept'")
            ->fetchOne();
        $this->assertNotEmpty($templateId);

        [$schemeId, $topId] = $this->createThesaurusOfConcepts();
        $connection->executeStatement(
            'UPDATE `resource` SET `resource_template_id` = ? WHERE `id` = ?',
            [$templateId, $topId]
        );

        $this->runJob(ConvertConceptsToItems::class, ['schemes' => [$schemeId]]);

        $this->api()->update('items', $topId, [], [], ['isPartial' => true, 'validateOnly' => true]);
        $dataTypes = json_decode((string) $connection
            ->executeQuery(
                'SELECT `data_type` FROM `resource_template_property` WHERE `resource_template_id` = ? AND `property_id` = ?',
                [$templateId, $this->propertyId('skos:broader')]
            )
            ->fetchOne(), true) ?: [];
        $this->assertContains('resource:item', $dataTypes);
    }

    /**
     * A thesaurus converted back into items can be converted again.
     */
    public function testConversionCanBeReplayed(): void
    {
        [$schemeId, $topId, $childId] = $this->createThesaurusOfConcepts();

        $this->runJob(ConvertConceptsToItems::class, ['schemes' => [$schemeId]]);
        $this->runJob(ConvertItemsToConcepts::class, ['schemes' => [$schemeId]]);

        foreach ([$topId, $childId] as $id) {
            $this->assertTrue($this->isConcept($id), "The item #$id was not converted again.");
            $this->assertFalse($this->isItem($id), "The concept #$id is still an item.");
        }
    }
}
