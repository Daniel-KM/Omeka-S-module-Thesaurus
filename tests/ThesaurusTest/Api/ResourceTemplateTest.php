<?php declare(strict_types=1);

namespace ThesaurusTest\Api;

use CommonTest\AbstractHttpControllerTestCase;
use ThesaurusTest\ThesaurusTestTrait;

/**
 * Tests the resource templates of the thesaurus and the selection of resources.
 */
class ResourceTemplateTest extends AbstractHttpControllerTestCase
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

    /**
     * @return string[]
     */
    protected function templateDataTypes(string $label, string $term): array
    {
        $template = $this->api()->read('resource_templates', ['label' => $label])->getContent();
        $templateProperty = $template->resourceTemplateProperty($this->propertyId($term));
        $this->assertNotNull($templateProperty, sprintf('The property %s is missing in the template %s.', $term, $label));
        return $templateProperty->dataTypes();
    }

    public function testRelationsBetweenConceptsUseConcepts(): void
    {
        foreach (['skos:broader', 'skos:narrower', 'skos:related', 'skos:semanticRelation', 'skos:closeMatch', 'skos:exactMatch'] as $term) {
            $this->assertEquals(['resource:concept'], $this->templateDataTypes('Thesaurus Concept', $term), $term);
        }
        $this->assertEquals(['resource:concept'], $this->templateDataTypes('Thesaurus Scheme', 'skos:hasTopConcept'));
    }

    public function testSchemeOfConceptIsAnItem(): void
    {
        $this->assertEquals(['resource:item'], $this->templateDataTypes('Thesaurus Concept', 'skos:inScheme'));
        $this->assertEquals(['resource:item'], $this->templateDataTypes('Thesaurus Concept', 'skos:topConceptOf'));
    }

    /**
     * The template of the concepts is reserved to the concepts, and the scheme
     * is selected among the thesaurus only.
     */
    public function testConceptTemplateIsReservedToConcepts(): void
    {
        if (!$this->getServiceLocator()->get('Omeka\ApiAdapterManager')->has('resource_templates')
            || !class_exists('AdvancedResourceTemplate\Module', false)
        ) {
            $this->markTestSkipped('Requires the module Advanced Resource Template.');
        }

        $template = $this->api()->read('resource_templates', ['label' => 'Thesaurus Concept'])->getContent();
        $this->assertEquals(['concepts'], $template->dataValue('use_for_resources'));

        $forItems = array_map('intval', array_keys($this->api()
            ->search('resource_templates', ['resource' => 'items'], ['returnScalar' => 'id'])
            ->getContent()));
        $forConcepts = array_map('intval', array_keys($this->api()
            ->search('resource_templates', ['resource' => 'concepts'], ['returnScalar' => 'id'])
            ->getContent()));
        $this->assertNotContains($template->id(), $forItems);
        $this->assertContains($template->id(), $forConcepts);

        $inScheme = $template->resourceTemplateProperty($this->propertyId('skos:inScheme'));
        $this->assertEquals('thesaurus_scheme=1', $inScheme->mainDataValues()['resource_query'] ?? null);
    }

    /**
     * The query "thesaurus_scheme" limits the items to the thesaurus schemes.
     */
    public function testQueryThesaurusScheme(): void
    {
        $schemeClassId = (int) $this->getServiceLocator()->get('Omeka\Settings')->get('thesaurus_skos_scheme_class_id');
        $this->assertNotEmpty($schemeClassId);

        // The helper createItem() skips the keys with a colon that are not a
        // property, so the class is set via the api.
        $scheme = $this->api()->create('items', [
            'o:resource_class' => ['o:id' => $schemeClassId],
        ])->getContent();
        $this->createdResources[] = ['type' => 'items', 'id' => $scheme->id()];
        $item = $this->createItem([
            'dcterms:title' => [['@value' => 'ZZ item']],
        ]);

        $ids = array_map('intval', array_keys($this->api()
            ->search('items', ['thesaurus_scheme' => 1], ['returnScalar' => 'id'])
            ->getContent()));

        $this->assertContains($scheme->id(), $ids);
        $this->assertNotContains($item->id(), $ids);
    }

    /**
     * The concepts are selectable in the sidebar of the resource form.
     */
    public function testSidebarSelectListsConcepts(): void
    {
        $scheme = $this->createItem([
            'dcterms:title' => [['@value' => 'ZZ scheme']],
        ]);
        $concept = $this->api()->create('concepts', [
            'skos:prefLabel' => [[
                'type' => 'literal',
                'property_id' => $this->propertyId('skos:prefLabel'),
                '@value' => 'ZZ concept to select',
            ]],
            'skos:topConceptOf' => [[
                'type' => 'resource:item',
                'property_id' => $this->propertyId('skos:topConceptOf'),
                'value_resource_id' => $scheme->id(),
            ]],
        ])->getContent();
        $this->createdResources[] = ['type' => 'concepts', 'id' => $concept->id()];

        $this->dispatch('/admin/concept/sidebar-select', 'GET', ['scheme_id' => $scheme->id()], true);
        $this->assertResponseStatusCode(200);

        // Without template, the title is not the preferred label, so the concept
        // is identified by its id.
        $body = html_entity_decode($this->getResponse()->getBody());
        $this->assertStringContainsString('value="' . $concept->id() . '"', $body);
        $this->assertStringContainsString('/admin/concept/' . $concept->id() . '/show-details', $body);
    }

    /**
     * The input of the data type displays a button to select a concept.
     */
    public function testDataTypeFormHasSelectButton(): void
    {
        $dataType = $this->getServiceLocator()->get('Omeka\DataTypeManager')->get('resource:concept');
        $view = $this->getServiceLocator()->get('ViewRenderer');
        $html = html_entity_decode($dataType->form($view));

        $this->assertStringContainsString('resource-select', $html);
        $this->assertStringContainsString('/admin/concept/sidebar-select', $html);
    }
}
