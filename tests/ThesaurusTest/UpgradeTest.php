<?php declare(strict_types=1);

namespace ThesaurusTest;

use CommonTest\AbstractHttpControllerTestCase;

/**
 * Tests the migrations.
 *
 * The migration 3.4.27 warns about the data of the concepts to fix, but does
 * not update or remove any data: the administrator selects the thesaurus to
 * process via the task of maintenance. The migration 3.4.28 creates the table
 * of the cache of the trees.
 */
class UpgradeTest extends AbstractHttpControllerTestCase
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

    protected function valueType(int $valueId): ?string
    {
        return $this->connection()
            ->executeQuery('SELECT `type` FROM `value` WHERE `id` = ?', [$valueId])
            ->fetchOne() ?: null;
    }

    /**
     * Create a concept of a new scheme and a value of custom vocab for it.
     *
     * @return array The id of the scheme and the id of the value.
     */
    protected function createBrokenValue(): array
    {
        $scheme = $this->createItem(['dcterms:title' => [['@value' => 'ZZ scheme']]]);
        $concept = $this->api()->create('concepts', [
            'skos:prefLabel' => [[
                'type' => 'literal',
                'property_id' => $this->propertyId('skos:prefLabel'),
                '@value' => 'ZZ concept',
            ]],
            'skos:topConceptOf' => [[
                'type' => 'resource:item',
                'property_id' => $this->propertyId('skos:topConceptOf'),
                'value_resource_id' => $scheme->id(),
            ]],
        ])->getContent();
        $this->createdResources[] = ['type' => 'concepts', 'id' => $concept->id()];

        $this->connection()->executeStatement(
            'INSERT INTO `value` (`resource_id`, `property_id`, `value_resource_id`, `type`, `is_public`) VALUES (?, ?, ?, ?, 1)',
            [$scheme->id(), $this->propertyId('dcterms:subject'), $concept->id(), 'customvocab:3']
        );

        return [(int) $scheme->id(), (int) $this->connection()->lastInsertId()];
    }

    /**
     * The values filled with a concept via a custom vocab are kept as is: only
     * the task of maintenance fixes them, on the selected thesaurus.
     */
    public function testValuesOfCustomVocabAreNotMigrated(): void
    {
        [$schemeId, $valueId] = $this->createBrokenValue();
        $this->assertEquals('customvocab:3', $this->valueType($valueId));

        $this->runUpgrade('3.4.26', '3.4.27');

        $this->assertEquals('customvocab:3', $this->valueType($valueId));
        $this->assertNotEquals('thesaurus:' . $schemeId, $this->valueType($valueId));
    }

    /**
     * The migration warns about the data to fix and lists the thesaurus.
     */
    /**
     * The data to fix are detected and the thesaurus to process are listed.
     *
     * These counts and labels build the warning of the migration, that invites
     * the administrator to run the task on the listed thesaurus.
     */
    public function testDataToFixAreDetected(): void
    {
        [$schemeId] = $this->createBrokenValue();

        $this->runUpgrade('3.4.26', '3.4.27');

        $conceptValues = new \Thesaurus\Stdlib\ConceptValues($this->connection());
        $this->assertGreaterThan(0, $conceptValues->countCustomVocabValues());
        $this->assertArrayHasKey($schemeId, $conceptValues->schemesWithCustomVocabValues());
    }

    /**
     * The values of the other resources are not changed.
     */
    public function testValuesOfItemsAreKept(): void
    {
        $item = $this->createItem(['dcterms:title' => [['@value' => 'ZZ item']]]);
        $other = $this->createItem(['dcterms:title' => [['@value' => 'ZZ other item']]]);

        $this->connection()->executeStatement(
            'INSERT INTO `value` (`resource_id`, `property_id`, `value_resource_id`, `type`, `is_public`) VALUES (?, ?, ?, ?, 1)',
            [$item->id(), $this->propertyId('dcterms:subject'), $other->id(), 'customvocab:3']
        );
        $valueId = (int) $this->connection()->lastInsertId();

        $this->runUpgrade('3.4.26', '3.4.27');

        $this->assertEquals('customvocab:3', $this->valueType($valueId));
    }

    protected function treeCacheDefinition(): string
    {
        return $this->connection()
            ->executeQuery('SHOW CREATE TABLE `thesaurus_tree_cache`')
            ->fetchAssociative()['Create Table'];
    }

    /**
     * The table of the cache is created as on a fresh install.
     */
    public function testTreeCacheTableIsCreated(): void
    {
        $definition = $this->treeCacheDefinition();
        $this->connection()->executeStatement('DROP TABLE `thesaurus_tree_cache`');

        $this->runUpgrade('3.4.27', '3.4.28');

        $this->assertSame($definition, $this->treeCacheDefinition());
    }

    /**
     * The migration can be replayed, even after an interrupted upgrade that
     * created the table without its foreign key.
     */
    public function testTreeCacheMigrationIsReplayable(): void
    {
        $definition = $this->treeCacheDefinition();

        $this->runUpgrade('3.4.27', '3.4.28');
        $this->assertSame($definition, $this->treeCacheDefinition());

        $this->connection()->executeStatement('ALTER TABLE `thesaurus_tree_cache` DROP FOREIGN KEY `FK_74BD0F4965797862`');
        $this->runUpgrade('3.4.27', '3.4.28');
        $this->assertSame($definition, $this->treeCacheDefinition());
    }
}
