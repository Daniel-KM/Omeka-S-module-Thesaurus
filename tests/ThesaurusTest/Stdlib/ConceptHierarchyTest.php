<?php declare(strict_types=1);

namespace ThesaurusTest\Stdlib;

use CommonTest\AbstractHttpControllerTestCase;
use Thesaurus\Job\ConvertItemsToConcepts;
use ThesaurusTest\ThesaurusTestTrait;

/**
 * Tests the ids of the descendants and the ascendants, read without the tree.
 */
class ConceptHierarchyTest extends AbstractHttpControllerTestCase
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
        $this->cleanupBackups();
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
     * Build a thesaurus of items: a top, two children and a grandchild.
     *
     * @return array Ids of the scheme, the top, the children and the grandchild.
     */
    protected function createThesaurusOfItems(): array
    {
        $scheme = $this->createItem(['dcterms:title' => [['@value' => 'ZZ scheme']]]);
        $top = $this->createItem(['skos:prefLabel' => [['@value' => 'ZZ top']]]);
        $childA = $this->createItem(['skos:prefLabel' => [['@value' => 'ZZ child A']]]);
        $childB = $this->createItem(['skos:prefLabel' => [['@value' => 'ZZ child B']]]);
        $grandChild = $this->createItem(['skos:prefLabel' => [['@value' => 'ZZ grandchild']]]);

        $values = [
            [$top->id(), 'skos:topConceptOf', $scheme->id()],
            [$scheme->id(), 'skos:hasTopConcept', $top->id()],
            [$childA->id(), 'skos:inScheme', $scheme->id()],
            [$childB->id(), 'skos:inScheme', $scheme->id()],
            [$grandChild->id(), 'skos:inScheme', $scheme->id()],
            // Both directions for the first child.
            [$childA->id(), 'skos:broader', $top->id()],
            [$top->id(), 'skos:narrower', $childA->id()],
            // Only the narrower for the second child, like a partial import.
            [$top->id(), 'skos:narrower', $childB->id()],
            // Only the broader for the grandchild.
            [$grandChild->id(), 'skos:broader', $childA->id()],
        ];
        foreach ($values as [$resourceId, $term, $valueResourceId]) {
            $this->connection()->executeStatement(
                'INSERT INTO `value` (`resource_id`, `property_id`, `value_resource_id`, `type`, `is_public`) VALUES (?, ?, ?, ?, 1)',
                [$resourceId, $this->propertyId($term), $valueResourceId, 'resource:item']
            );
        }

        return [(int) $scheme->id(), (int) $top->id(), (int) $childA->id(), (int) $childB->id(), (int) $grandChild->id()];
    }

    public function testDescendantsOfItems(): void
    {
        [, $topId, $childAId, $childBId, $grandChildId] = $this->createThesaurusOfItems();

        $this->assertEqualsCanonicalizing(
            [$childAId, $childBId, $grandChildId],
            $this->thesaurus()->descendantIds($topId)
        );
        $this->assertEqualsCanonicalizing(
            [$childAId, $grandChildId],
            $this->thesaurus()->descendantIds($childAId, true)
        );
        $this->assertSame([], $this->thesaurus()->descendantIds($grandChildId));
    }

    public function testAscendantsOfItems(): void
    {
        [, $topId, $childAId, $childBId, $grandChildId] = $this->createThesaurusOfItems();

        $this->assertSame([$childAId, $topId], $this->thesaurus()->ascendantIds($grandChildId));
        $this->assertSame([$childBId, $topId], $this->thesaurus()->ascendantIds($childBId, true));
        $this->assertSame([], $this->thesaurus()->ascendantIds($topId));
    }

    /**
     * A converted thesaurus uses the index of the concepts.
     */
    public function testHierarchyOfConcepts(): void
    {
        [$schemeId, $topId, $childAId, $childBId, $grandChildId] = $this->createThesaurusOfItems();
        $this->runJob(ConvertItemsToConcepts::class, ['schemes' => [$schemeId]]);

        $this->assertSame(1, (int) $this->connection()
            ->executeQuery('SELECT COUNT(*) FROM `concept` WHERE `id` = ? AND `broader_id` = ?', [$grandChildId, $childAId])
            ->fetchOne(), 'The index of the concepts is not filled.');

        $this->assertEqualsCanonicalizing(
            [$childAId, $childBId, $grandChildId],
            $this->thesaurus()->descendantIds($topId)
        );
        $this->assertSame([$childAId, $topId], $this->thesaurus()->ascendantIds($grandChildId));
    }

    /**
     * The search of the items by a concept and its descendants works with a
     * converted thesaurus.
     */
    public function testSearchByConceptAndDescendants(): void
    {
        [$schemeId, $topId, , , $grandChildId] = $this->createThesaurusOfItems();
        $this->runJob(ConvertItemsToConcepts::class, ['schemes' => [$schemeId]]);

        $item = $this->createItem(['dcterms:title' => [['@value' => 'ZZ item with subject']]]);
        $this->connection()->executeStatement(
            'INSERT INTO `value` (`resource_id`, `property_id`, `value_resource_id`, `type`, `is_public`) VALUES (?, ?, ?, ?, 1)',
            [$item->id(), $this->propertyId('dcterms:subject'), $grandChildId, 'thesaurus:' . $schemeId]
        );

        $ids = $this->api()->search('items', [
            'property' => [[
                'property' => 'dcterms:subject',
                'type' => 'cat',
                'text' => (string) $topId,
            ]],
        ], ['returnScalar' => 'id'])->getContent();

        $this->assertContains((int) $item->id(), array_map('intval', array_keys($ids)));
    }
}
