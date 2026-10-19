<?php declare(strict_types=1);

namespace ThesaurusTest;

use CommonTest\AbstractHttpControllerTestCase;
use Thesaurus\Job\ConvertItemsToConcepts;

/**
 * Tests the upgrade and the conversion of a thesaurus stored as items.
 *
 * Until version 3.4.25, the concepts were items indexed in the table
 * "thesaurus_term", that is recreated here, because the test database is a new
 * install. The upgrade keeps these data as is and the conversion is done by the
 * task, on the thesaurus selected by the administrator.
 */
class OldIndexTest extends AbstractHttpControllerTestCase
{
    use ThesaurusTestTrait;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
        $this->dropOldIndex();
        $this->createOldIndex();
    }

    public function tearDown(): void
    {
        $this->dropOldIndex();
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

    protected function hasOldIndex(): bool
    {
        $sql = <<<'SQL'
            SELECT COUNT(*)
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'thesaurus_term'
            SQL;
        return (bool) (int) $this->connection()->executeQuery($sql)->fetchOne();
    }

    protected function createOldIndex(): void
    {
        // The comments are removed first: a statement is executed alone.
        $lines = array_filter(
            array_map('trim', explode("\n", $this->getFixture('thesaurus_term.sql'))),
            fn ($line) => $line !== '' && mb_substr($line, 0, 2) !== '--'
        );
        foreach (explode(';', implode("\n", $lines)) as $sql) {
            $sql = trim($sql);
            if ($sql !== '') {
                $this->connection()->executeStatement($sql);
            }
        }
    }

    protected function dropOldIndex(): void
    {
        $this->connection()->executeStatement('DROP TABLE IF EXISTS `thesaurus_term`');
    }

    /**
     * Build a thesaurus of items indexed in the old table.
     *
     * @return array Ids of the scheme, of the top concept and of the child.
     */
    protected function createThesaurusOfItems(): array
    {
        $scheme = $this->createItem(['dcterms:title' => [['@value' => 'ZZ scheme']]]);
        $top = $this->createItem(['skos:prefLabel' => [['@value' => 'ZZ racine']]]);
        $child = $this->createItem(['skos:prefLabel' => [['@value' => 'ZZ enfant']]]);

        // The relations of the thesaurus are values, like before version 3.4.26.
        $this->connection()->executeStatement(
            'INSERT INTO `value` (`resource_id`, `property_id`, `value_resource_id`, `type`, `is_public`) VALUES (?, ?, ?, ?, 1)',
            [$top->id(), $this->propertyId('skos:topConceptOf'), $scheme->id(), 'resource:item']
        );
        $this->connection()->executeStatement(
            'INSERT INTO `value` (`resource_id`, `property_id`, `value_resource_id`, `type`, `is_public`) VALUES (?, ?, ?, ?, 1)',
            [$child->id(), $this->propertyId('skos:broader'), $top->id(), 'resource:item']
        );

        $this->connection()->executeStatement(
            'INSERT INTO `thesaurus_term` (`item_id`, `scheme_id`, `root_id`, `broader_id`, `position`) VALUES (?, ?, NULL, NULL, 1)',
            [$top->id(), $scheme->id()]
        );
        $rootId = (int) $this->connection()->lastInsertId();
        $this->connection()->executeStatement(
            'UPDATE `thesaurus_term` SET `root_id` = ? WHERE `id` = ?',
            [$rootId, $rootId]
        );
        $this->connection()->executeStatement(
            'INSERT INTO `thesaurus_term` (`item_id`, `scheme_id`, `root_id`, `broader_id`, `position`) VALUES (?, ?, ?, ?, 2)',
            [$child->id(), $scheme->id(), $rootId, $rootId]
        );

        return [(int) $scheme->id(), (int) $top->id(), (int) $child->id()];
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

    /**
     * The upgrade creates the table of the concepts, but converts nothing.
     */
    public function testUpgradeKeepsItemsAndOldIndex(): void
    {
        [, $topId, $childId] = $this->createThesaurusOfItems();

        $this->runUpgrade('3.4.25', '3.4.27');

        $this->assertTrue($this->hasOldIndex(), 'The old index table was removed by the upgrade.');
        $this->assertEquals(2, (int) $this->connection()->executeQuery('SELECT COUNT(*) FROM `thesaurus_term`')->fetchOne());
        foreach ([$topId, $childId] as $id) {
            $this->assertEquals('Omeka\Entity\Item', $this->resourceType($id));
            $this->assertTrue($this->isItem($id), "The item #$id was converted by the upgrade.");
            $this->assertEquals(0, (int) $this->connection()
                ->executeQuery('SELECT COUNT(*) FROM `concept` WHERE `id` = ?', [$id])
                ->fetchOne());
        }
    }

    /**
     * The task converts the selected thesaurus and cleans the old index.
     */
    public function testTaskConvertsAndRemovesOldIndex(): void
    {
        [$schemeId, $topId, $childId] = $this->createThesaurusOfItems();

        $this->runUpgrade('3.4.25', '3.4.27');
        $this->runJob(ConvertItemsToConcepts::class, ['schemes' => [$schemeId]]);

        foreach ([$topId, $childId] as $id) {
            $this->assertEquals('Thesaurus\Entity\Concept', $this->resourceType($id));
            $this->assertFalse($this->isItem($id), "The concept #$id is still an item.");
        }
        $this->assertEquals('Omeka\Entity\Item', $this->resourceType($schemeId));

        // All the concepts are converted, so the old index table is useless.
        $this->assertFalse($this->hasOldIndex(), 'The old index table was kept after the conversion.');
    }

    /**
     * The rows of the thesaurus that are not converted are kept.
     */
    public function testOldIndexIsKeptForOtherThesaurus(): void
    {
        [$schemeId] = $this->createThesaurusOfItems();
        $other = $this->createItem(['dcterms:title' => [['@value' => 'ZZ other scheme']]]);
        $otherConcept = $this->createItem(['skos:prefLabel' => [['@value' => 'ZZ other racine']]]);
        $this->connection()->executeStatement(
            'INSERT INTO `thesaurus_term` (`item_id`, `scheme_id`, `position`) VALUES (?, ?, 1)',
            [$otherConcept->id(), $other->id()]
        );

        $this->runUpgrade('3.4.25', '3.4.27');
        $this->runJob(ConvertItemsToConcepts::class, ['schemes' => [$schemeId]]);

        $this->assertTrue($this->hasOldIndex(), 'The old index table was removed too early.');
        $this->assertEquals(1, (int) $this->connection()
            ->executeQuery('SELECT COUNT(*) FROM `thesaurus_term` WHERE `scheme_id` = ?', [$other->id()])
            ->fetchOne());
        $this->assertEquals(0, (int) $this->connection()
            ->executeQuery('SELECT COUNT(*) FROM `thesaurus_term` WHERE `scheme_id` = ?', [$schemeId])
            ->fetchOne());
        $this->assertTrue($this->isItem((int) $otherConcept->id()), 'The concept of the other thesaurus was converted.');
    }
}
