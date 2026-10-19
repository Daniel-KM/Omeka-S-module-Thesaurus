<?php declare(strict_types=1);

namespace ThesaurusTest\Controller\Site;

use CommonTest\AbstractHttpControllerTestCase;
use ThesaurusTest\ThesaurusTestTrait;

/**
 * Tests the public page of a concept and the redirection from the item page.
 */
class ConceptControllerTest extends AbstractHttpControllerTestCase
{
    use ThesaurusTestTrait;

    /**
     * @var \Omeka\Api\Representation\SiteRepresentation
     */
    protected $site;

    /**
     * @var \Thesaurus\Api\Representation\ConceptRepresentation
     */
    protected $concept;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();

        $this->site = $this->api()->create('sites', [
            'o:title' => 'ZZ site',
            'o:slug' => 'zz-site',
            'o:theme' => 'default',
        ])->getContent();
        $this->createdResources[] = ['type' => 'sites', 'id' => $this->site->id()];

        $easyMeta = $this->getServiceLocator()->get('Common\EasyMeta');
        $scheme = $this->createItem(['dcterms:title' => [['@value' => 'ZZ scheme']]]);
        $this->concept = $this->api()->create('concepts', [
            'skos:prefLabel' => [[
                'type' => 'literal',
                'property_id' => $easyMeta->propertyId('skos:prefLabel'),
                '@value' => 'ZZ concept',
            ]],
            'skos:topConceptOf' => [[
                'type' => 'resource:item',
                'property_id' => $easyMeta->propertyId('skos:topConceptOf'),
                'value_resource_id' => $scheme->id(),
            ]],
        ])->getContent();
        $this->createdResources[] = ['type' => 'concepts', 'id' => $this->concept->id()];
    }

    public function tearDown(): void
    {
        // The dispatch without authentication clears the identity.
        $this->loginAdmin();
        $this->cleanupResources();
        parent::tearDown();
    }

    public function testShowConcept(): void
    {
        $this->dispatchUnauthenticated('/s/zz-site/concept/' . $this->concept->id());
        $this->assertResponseStatusCode(200);
        $this->assertStringContainsString('ZZ concept', $this->getResponse()->getBody());
    }

    public function testShowMissingConcept(): void
    {
        $this->dispatchUnauthenticated('/s/zz-site/concept/999999999');
        $this->assertResponseStatusCode(404);
    }

    /**
     * The id is kept when an item is converted into a concept, so the old url
     * of the item is redirected.
     */
    public function testItemPageRedirectsToConcept(): void
    {
        $this->dispatchUnauthenticated('/s/zz-site/item/' . $this->concept->id());
        $this->assertResponseStatusCode(301);
        $this->assertRedirectRegex('~/s/zz-site/concept/' . $this->concept->id() . '$~');
    }

    public function testAdminItemPageRedirectsToConcept(): void
    {
        $this->dispatch('/admin/item/' . $this->concept->id());
        $this->assertResponseStatusCode(301);
        $this->assertRedirectRegex('~/admin/concept/' . $this->concept->id() . '$~');
    }

    public function testItemPageIsNotRedirected(): void
    {
        $item = $this->createItem(['dcterms:title' => [['@value' => 'ZZ item']]]);
        $this->dispatchUnauthenticated('/s/zz-site/item/' . $item->id());
        $this->assertResponseStatusCode(200);
    }
}
