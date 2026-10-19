<?php declare(strict_types=1);

namespace ThesaurusTest\Controller\Admin;

use CommonTest\AbstractHttpControllerTestCase;
use ThesaurusTest\ThesaurusTestTrait;

/**
 * Tests the addition of a concept from its thesaurus or its broader concept.
 */
class ConceptAddTest extends AbstractHttpControllerTestCase
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

    protected function createTop(int $schemeId, string $label): \Thesaurus\Api\Representation\ConceptRepresentation
    {
        $concept = $this->api()->create('concepts', [
            'skos:prefLabel' => [['type' => 'literal', 'property_id' => $this->propertyId('skos:prefLabel'), '@value' => $label]],
            'skos:topConceptOf' => [['type' => 'resource:item', 'property_id' => $this->propertyId('skos:topConceptOf'), 'value_resource_id' => $schemeId]],
            'o-module-thesaurus:position' => 1,
        ])->getContent();
        $this->createdResources[] = ['type' => 'concepts', 'id' => $concept->id()];
        return $concept;
    }

    /**
     * Get the values prefilled in the form of addition.
     */
    protected function prefilledValues(): array
    {
        $body = $this->getResponse()->getBody();
        $matches = [];
        preg_match('~data-values-json="([^"]*)"~', $body, $matches);
        $this->assertNotEmpty($matches, 'The form has no values.');
        return json_decode(html_entity_decode($matches[1]), true) ?: [];
    }

    public function testAddTopConceptIsPrefilled(): void
    {
        $scheme = $this->createItem(['dcterms:title' => [['@value' => 'ZZ scheme']]]);

        $this->dispatch('/admin/concept/add', 'GET', ['scheme_id' => $scheme->id()]);
        $this->assertResponseStatusCode(200);

        $values = $this->prefilledValues();
        $this->assertEquals($scheme->id(), $values['skos:inScheme']['values'][0]['value_resource_id']);
        $this->assertEquals($scheme->id(), $values['skos:topConceptOf']['values'][0]['value_resource_id']);
        $this->assertArrayNotHasKey('skos:broader', $values);
    }

    public function testAddNarrowerConceptIsPrefilled(): void
    {
        $scheme = $this->createItem(['dcterms:title' => [['@value' => 'ZZ scheme']]]);
        $top = $this->createTop($scheme->id(), 'ZZ top');

        $this->dispatch('/admin/concept/add', 'GET', ['scheme_id' => $scheme->id(), 'broader_id' => $top->id()]);
        $this->assertResponseStatusCode(200);

        $values = $this->prefilledValues();
        $this->assertEquals($scheme->id(), $values['skos:inScheme']['values'][0]['value_resource_id']);
        $this->assertEquals($top->id(), $values['skos:broader']['values'][0]['value_resource_id']);
        $this->assertEquals('resource:concept', $values['skos:broader']['values'][0]['type']);
        $this->assertArrayNotHasKey('skos:topConceptOf', $values);
    }

    /**
     * A broader concept of another thesaurus is ignored.
     */
    public function testBroaderOfAnotherSchemeIsIgnored(): void
    {
        $schemeA = $this->createItem(['dcterms:title' => [['@value' => 'ZZ scheme A']]]);
        $schemeB = $this->createItem(['dcterms:title' => [['@value' => 'ZZ scheme B']]]);
        $topB = $this->createTop($schemeB->id(), 'ZZ top B');

        $this->dispatch('/admin/concept/add', 'GET', ['scheme_id' => $schemeA->id(), 'broader_id' => $topB->id()]);
        $this->assertResponseStatusCode(200);

        $values = $this->prefilledValues();
        $this->assertArrayNotHasKey('skos:broader', $values);
        $this->assertEquals($schemeA->id(), $values['skos:topConceptOf']['values'][0]['value_resource_id']);
    }

    /**
     * The new concept is appended after its siblings, so the tree does not need
     * to be reindexed.
     */
    public function testNextPosition(): void
    {
        $scheme = $this->createItem(['dcterms:title' => [['@value' => 'ZZ scheme']]]);
        $top = $this->createTop($scheme->id(), 'ZZ top');

        $method = new \ReflectionMethod(\Thesaurus\Controller\Admin\ConceptController::class, 'nextPosition');
        $method->setAccessible(true);
        $controller = $this->getServiceLocator()->get('ControllerManager')->get(\Thesaurus\Controller\Admin\ConceptController::class);
        $controller->setPluginManager($this->getServiceLocator()->get('ControllerPluginManager'));

        $this->assertEquals(2, $method->invoke($controller, $scheme->id(), 0));
        $this->assertEquals(1, $method->invoke($controller, $scheme->id(), $top->id()));
    }

    public function testAddWithoutSchemeIsNotFound(): void
    {
        $this->dispatch('/admin/concept/add');
        $this->assertResponseStatusCode(404);
    }
}
