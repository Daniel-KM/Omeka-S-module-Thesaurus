<?php declare(strict_types=1);

namespace ThesaurusTest\Job;

use CommonTest\AbstractHttpControllerTestCase;
use Thesaurus\Job\FixConceptReferences;
use ThesaurusTest\ThesaurusTestTrait;

/**
 * Tests the task that fixes the data of the concepts of some thesaurus.
 */
class FixConceptReferencesTest extends AbstractHttpControllerTestCase
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
     * Create a thesaurus with a concept and a value of custom vocab for it.
     *
     * @return array The id of the scheme and the id of the value.
     */
    protected function createBrokenThesaurus(string $label): array
    {
        $scheme = $this->createItem(['dcterms:title' => [['@value' => $label]]]);
        $concept = $this->api()->create('concepts', [
            'skos:prefLabel' => [[
                'type' => 'literal',
                'property_id' => $this->propertyId('skos:prefLabel'),
                '@value' => $label . ' concept',
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
     * Without selected thesaurus, the task does not change any data.
     */
    public function testNoSelectionDoesNothing(): void
    {
        [, $valueId] = $this->createBrokenThesaurus('ZZ scheme');

        $this->runJob(FixConceptReferences::class, []);

        $this->assertEquals('customvocab:3', $this->valueType($valueId));
    }

    /**
     * Only the concepts of the selected thesaurus are fixed.
     */
    public function testOnlySelectedThesaurusIsFixed(): void
    {
        [$schemeId, $valueId] = $this->createBrokenThesaurus('ZZ scheme');
        [, $otherValueId] = $this->createBrokenThesaurus('ZZ other scheme');

        $this->runJob(FixConceptReferences::class, ['schemes' => [$schemeId]]);

        $this->assertEquals('thesaurus:' . $schemeId, $this->valueType($valueId));
        $this->assertEquals('customvocab:3', $this->valueType($otherValueId));
    }

    /**
     * The task can be replayed without changing anything.
     */
    public function testTaskIsIdempotent(): void
    {
        [$schemeId, $valueId] = $this->createBrokenThesaurus('ZZ scheme');

        $this->runJob(FixConceptReferences::class, ['schemes' => [$schemeId]]);
        $this->runJob(FixConceptReferences::class, ['schemes' => [$schemeId]]);

        $this->assertEquals('thesaurus:' . $schemeId, $this->valueType($valueId));
    }
}
