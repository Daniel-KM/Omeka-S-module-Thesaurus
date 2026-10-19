<?php declare(strict_types=1);

namespace ThesaurusTest\Stdlib;

use CommonTest\AbstractHttpControllerTestCase;
use ThesaurusTest\ThesaurusTestTrait;

/**
 * Tests the reading of a thesaurus that is not converted into concepts.
 *
 * Until version 3.4.25, the concepts were items. The conversion is done by a
 * task, on the thesaurus selected by the administrator, so the structure is
 * still read from the items until then.
 */
class ThesaurusOfItemsTest extends AbstractHttpControllerTestCase
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

    protected function thesaurus(int $id): \Thesaurus\Stdlib\Thesaurus
    {
        // A new instance is required: the structure is cached by thesaurus.
        $thesaurus = $this->getServiceLocator()->build('Thesaurus\Thesaurus');
        return $thesaurus($id);
    }

    /**
     * Build a thesaurus of items, like before version 3.4.26.
     *
     * @return array Ids of the scheme, of the top concept and of the child.
     */
    protected function createThesaurusOfItems(): array
    {
        $scheme = $this->createItem(['dcterms:title' => [['@value' => 'ZZ scheme']]]);
        $top = $this->createItem(['skos:prefLabel' => [['@value' => 'ZZ racine']]]);
        $child = $this->createItem(['skos:prefLabel' => [['@value' => 'ZZ enfant']]]);

        $values = [
            [$scheme->id(), 'skos:hasTopConcept', $top->id()],
            [$top->id(), 'skos:topConceptOf', $scheme->id()],
            [$top->id(), 'skos:inScheme', $scheme->id()],
            [$top->id(), 'skos:narrower', $child->id()],
            [$child->id(), 'skos:inScheme', $scheme->id()],
            [$child->id(), 'skos:broader', $top->id()],
        ];
        foreach ($values as [$resourceId, $term, $valueResourceId]) {
            $this->connection()->executeStatement(
                'INSERT INTO `value` (`resource_id`, `property_id`, `value_resource_id`, `type`, `is_public`) VALUES (?, ?, ?, ?, 1)',
                [$resourceId, $this->propertyId($term), $valueResourceId, 'resource:item']
            );
        }

        return [(int) $scheme->id(), (int) $top->id(), (int) $child->id()];
    }

    /**
     * The scheme of a thesaurus of items is detected.
     */
    public function testSchemeIsDetected(): void
    {
        [$schemeId] = $this->createThesaurusOfItems();

        $thesaurus = $this->thesaurus($schemeId);

        $this->assertTrue($thesaurus->isSkos());
        $this->assertTrue($thesaurus->isScheme());
    }

    /**
     * The structure is read from the items, so the thesaurus is not empty.
     */
    public function testStructureIsReadFromItems(): void
    {
        [$schemeId, $topId, $childId] = $this->createThesaurusOfItems();

        $flatTree = $this->thesaurus($schemeId)->flatTree();

        $this->assertNotEmpty($flatTree, 'The thesaurus of items appears empty.');
        $this->assertEqualsCanonicalizing([$topId, $childId], array_keys($flatTree));
        $this->assertSame(0, $flatTree[$topId]['level']);
        $this->assertSame(1, $flatTree[$childId]['level']);
    }

    /**
     * A relation set in one direction only is part of the tree, for example
     * after a partial import.
     */
    public function testRelationInOneDirectionIsInTree(): void
    {
        [$schemeId, $topId, $childId] = $this->createThesaurusOfItems();

        // A grandchild with a value "skos:broader" only, and another child with
        // a value "skos:narrower" on the top only.
        $grandChild = $this->createItem(['skos:prefLabel' => [['@value' => 'ZZ petit-enfant']]]);
        $other = $this->createItem(['skos:prefLabel' => [['@value' => 'ZZ autre']]]);
        $values = [
            [$grandChild->id(), 'skos:inScheme', $schemeId],
            [$grandChild->id(), 'skos:broader', $childId],
            [$other->id(), 'skos:inScheme', $schemeId],
            [$topId, 'skos:narrower', $other->id()],
        ];
        foreach ($values as [$resourceId, $term, $valueResourceId]) {
            $this->connection()->executeStatement(
                'INSERT INTO `value` (`resource_id`, `property_id`, `value_resource_id`, `type`, `is_public`) VALUES (?, ?, ?, ?, 1)',
                [$resourceId, $this->propertyId($term), $valueResourceId, 'resource:item']
            );
        }

        $flatTree = $this->thesaurus($schemeId)->flatTree();

        $this->assertArrayHasKey($grandChild->id(), $flatTree);
        $this->assertSame(2, $flatTree[$grandChild->id()]['level']);
        $this->assertArrayHasKey($other->id(), $flatTree);
        $this->assertSame(1, $flatTree[$other->id()]['level']);
    }

    /**
     * The tops and the children are read from the items.
     */
    public function testTreeIsReadFromItems(): void
    {
        [$schemeId, $topId, $childId] = $this->createThesaurusOfItems();

        $tree = $this->thesaurus($schemeId)->tree();

        $this->assertArrayHasKey($topId, $tree);
        $this->assertEquals([$childId], array_keys($tree[$topId]['children']));
    }
}
