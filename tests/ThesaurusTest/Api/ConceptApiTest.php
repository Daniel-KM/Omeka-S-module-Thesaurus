<?php declare(strict_types=1);

namespace ThesaurusTest\Api;

use CommonTest\AbstractHttpControllerTestCase;
use Omeka\Api\Exception\ValidationException;
use ThesaurusTest\ThesaurusTestTrait;

/**
 * Tests for the api of the concepts, that are a dedicated resource type.
 */
class ConceptApiTest extends AbstractHttpControllerTestCase
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

    protected function createScheme(): \Omeka\Api\Representation\ItemRepresentation
    {
        return $this->createItem([
            'dcterms:title' => [['@value' => 'ZZ scheme']],
        ]);
    }

    /**
     * Create a concept with skos values only, so the structure is filled from
     * them, like the job of reindexation does.
     */
    protected function createConcept(string $label, array $values = []): \Thesaurus\Api\Representation\ConceptRepresentation
    {
        $data = [
            'skos:prefLabel' => [[
                'type' => 'literal',
                'property_id' => $this->propertyId('skos:prefLabel'),
                '@value' => $label,
            ]],
        ] + $values;

        $concept = $this->api()->create('concepts', $data)->getContent();
        $this->createdResources[] = ['type' => 'concepts', 'id' => $concept->id()];

        return $concept;
    }

    protected function valueTopConceptOf(int $schemeId): array
    {
        return [[
            'type' => 'resource:item',
            'property_id' => $this->propertyId('skos:topConceptOf'),
            'value_resource_id' => $schemeId,
        ]];
    }

    protected function valueBroader(int $conceptId): array
    {
        return [[
            'type' => 'resource:concept',
            'property_id' => $this->propertyId('skos:broader'),
            'value_resource_id' => $conceptId,
        ]];
    }

    /**
     * A concept without scheme is refused with a clear message, not a type
     * error.
     */
    public function testConceptRequiresAScheme(): void
    {
        try {
            $this->api()->create('concepts', [
                'skos:prefLabel' => [[
                    'type' => 'literal',
                    'property_id' => $this->propertyId('skos:prefLabel'),
                    '@value' => 'ZZ sans scheme',
                ]],
            ]);
            $this->fail('A concept without scheme should be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('o-module-thesaurus:scheme', $e->getErrorStore()->getErrors());
        }
    }

    /**
     * The skos values are the source of the structure: a concept created with
     * them only has its columns filled.
     */
    public function testStructureIsFilledFromValues(): void
    {
        $scheme = $this->createScheme();

        $top = $this->createConcept('ZZ racine', [
            'skos:topConceptOf' => $this->valueTopConceptOf($scheme->id()),
        ]);
        $this->assertEquals($scheme->id(), $top->scheme()->id());
        $this->assertNull($top->broader());
        $this->assertNotNull($top->top());
        $this->assertEquals($top->id(), $top->top()->id());
        $this->assertTrue($top->isTop());

        $child = $this->createConcept('ZZ enfant', [
            'skos:inScheme' => [[
                'type' => 'resource:item',
                'property_id' => $this->propertyId('skos:inScheme'),
                'value_resource_id' => $scheme->id(),
            ]],
            'skos:broader' => $this->valueBroader($top->id()),
        ]);
        $this->assertEquals($scheme->id(), $child->scheme()->id());
        $this->assertNotNull($child->broader());
        $this->assertEquals($top->id(), $child->broader()->id());
        $this->assertEquals($top->id(), $child->top()->id());
        $this->assertFalse($child->isTop());
    }

    /**
     * The keys "o:" take precedence over the values.
     */
    public function testStructuralKeysTakePrecedence(): void
    {
        $scheme = $this->createScheme();
        $top = $this->createConcept('ZZ racine', [
            'skos:topConceptOf' => $this->valueTopConceptOf($scheme->id()),
        ]);
        $other = $this->createConcept('ZZ autre racine', [
            'skos:topConceptOf' => $this->valueTopConceptOf($scheme->id()),
        ]);

        // The value says "top", the key says "broader": the key wins.
        $concept = $this->createConcept('ZZ explicite', [
            'skos:inScheme' => [[
                'type' => 'resource:item',
                'property_id' => $this->propertyId('skos:inScheme'),
                'value_resource_id' => $scheme->id(),
            ]],
            'skos:broader' => $this->valueBroader($top->id()),
            'o-module-thesaurus:broader' => ['o:id' => $other->id()],
            'o-module-thesaurus:position' => 5,
        ]);

        $this->assertEquals($other->id(), $concept->broader()->id());
        $this->assertEquals(5, $concept->position());
    }

    /**
     * The keys of the core, used until version 3.4.26, are still accepted on
     * input, but the keys of the module take precedence.
     */
    public function testLegacyStructureKeysAreAccepted(): void
    {
        $scheme = $this->createScheme();
        $top = $this->createConcept('ZZ racine', [
            'skos:topConceptOf' => $this->valueTopConceptOf($scheme->id()),
        ]);
        $other = $this->createConcept('ZZ autre racine', [
            'skos:topConceptOf' => $this->valueTopConceptOf($scheme->id()),
        ]);

        $legacy = $this->createConcept('ZZ ancien', [
            'o:scheme' => ['o:id' => $scheme->id()],
            'o:broader' => ['o:id' => $top->id()],
            'o:position' => 7,
        ]);
        $this->assertEquals($scheme->id(), $legacy->scheme()->id());
        $this->assertEquals($top->id(), $legacy->broader()->id());
        $this->assertEquals(7, $legacy->position());

        $both = $this->createConcept('ZZ les deux', [
            'o:scheme' => ['o:id' => $scheme->id()],
            'o:broader' => ['o:id' => $top->id()],
            'o-module-thesaurus:broader' => ['o:id' => $other->id()],
        ]);
        $this->assertEquals($other->id(), $both->broader()->id());

        // The legacy key works on a partial update too.
        $updated = $this->api()
            ->update('concepts', $legacy->id(), ['o:position' => 9], [], ['isPartial' => true])
            ->getContent();
        $this->assertEquals(9, $updated->position());
    }

    /**
     * The broader concept must belong to the same scheme and a concept cannot
     * be its own ancestor.
     */
    public function testBroaderIsChecked(): void
    {
        $schemeA = $this->createScheme();
        $schemeB = $this->createScheme();
        $topA = $this->createConcept('ZZ racine A', [
            'skos:topConceptOf' => $this->valueTopConceptOf($schemeA->id()),
        ]);

        try {
            $this->api()->create('concepts', [
                'skos:prefLabel' => [[
                    'type' => 'literal',
                    'property_id' => $this->propertyId('skos:prefLabel'),
                    '@value' => 'ZZ mauvais scheme',
                ]],
                'skos:inScheme' => [[
                    'type' => 'resource:item',
                    'property_id' => $this->propertyId('skos:inScheme'),
                    'value_resource_id' => $schemeB->id(),
                ]],
                'o-module-thesaurus:broader' => ['o:id' => $topA->id()],
            ]);
            $this->fail('A broader concept of another scheme should be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('o-module-thesaurus:broader', $e->getErrorStore()->getErrors());
        }

        try {
            $this->api()->update('concepts', $topA->id(), [
                'o-module-thesaurus:broader' => ['o:id' => $topA->id()],
            ], [], ['isPartial' => true]);
            $this->fail('A concept cannot be its own broader concept.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('o-module-thesaurus:broader', $e->getErrorStore()->getErrors());
        }
    }

    /**
     * The json-ld contains the structural keys and the skos values, and the
     * type uses the prefix declared in the api context.
     */
    public function testJsonLd(): void
    {
        $scheme = $this->createScheme();
        $top = $this->createConcept('ZZ racine', [
            'skos:topConceptOf' => $this->valueTopConceptOf($scheme->id()),
        ]);
        $child = $this->createConcept('ZZ enfant', [
            'skos:inScheme' => [[
                'type' => 'resource:item',
                'property_id' => $this->propertyId('skos:inScheme'),
                'value_resource_id' => $scheme->id(),
            ]],
            'skos:broader' => $this->valueBroader($top->id()),
        ]);

        $json = json_decode(json_encode($child), true);

        $this->assertContains('o-module-thesaurus:Concept', (array) $json['@type']);
        foreach (['o-module-thesaurus:scheme', 'o-module-thesaurus:broader', 'o-module-thesaurus:top', 'o-module-thesaurus:position'] as $key) {
            $this->assertArrayHasKey($key, $json);
        }
        $this->assertEquals($top->id(), $json['o-module-thesaurus:broader']['o:id']);

        // The keys of the core, used until version 3.4.26, are no more output.
        foreach (['o:scheme', 'o:broader', 'o:top', 'o:position'] as $key) {
            $this->assertArrayNotHasKey($key, $json);
        }

        // The skos relations are values, so they are lists, not references.
        $this->assertArrayHasKey('skos:broader', $json);
        $this->assertIsArray($json['skos:broader']);
        $this->assertArrayHasKey(0, $json['skos:broader']);
        $this->assertEquals('resource:concept', $json['skos:broader'][0]['type']);
    }

    /**
     * A concept is searchable by its own fields.
     */
    public function testSearchByStructure(): void
    {
        $scheme = $this->createScheme();
        $top = $this->createConcept('ZZ racine', [
            'skos:topConceptOf' => $this->valueTopConceptOf($scheme->id()),
        ]);
        $child = $this->createConcept('ZZ enfant', [
            'skos:inScheme' => [[
                'type' => 'resource:item',
                'property_id' => $this->propertyId('skos:inScheme'),
                'value_resource_id' => $scheme->id(),
            ]],
            'skos:broader' => $this->valueBroader($top->id()),
        ]);

        $ids = $this->api()->search('concepts', ['scheme_id' => $scheme->id()], ['returnScalar' => 'id'])->getContent();
        $this->assertEqualsCanonicalizing([$top->id(), $child->id()], array_map('intval', array_values($ids)));

        $ids = $this->api()->search('concepts', ['broader_id' => $top->id()], ['returnScalar' => 'id'])->getContent();
        $this->assertEquals([$child->id()], array_map('intval', array_values($ids)));
    }

    /**
     * A concept is not an item: it must not appear in the browse of the items.
     */
    public function testConceptIsNotAnItem(): void
    {
        $scheme = $this->createScheme();
        $top = $this->createConcept('ZZ racine', [
            'skos:topConceptOf' => $this->valueTopConceptOf($scheme->id()),
        ]);

        $ids = $this->api()->search('items', [], ['returnScalar' => 'id'])->getContent();
        $ids = array_map('intval', array_values($ids));
        $this->assertContains($scheme->id(), $ids);
        $this->assertNotContains($top->id(), $ids);
    }
}
