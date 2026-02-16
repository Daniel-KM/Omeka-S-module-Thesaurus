<?php declare(strict_types=1);

namespace ThesaurusTest\Controller\Admin;

use CommonTest\AbstractHttpControllerTestCase;
use ThesaurusTest\ThesaurusTestTrait;

/**
 * Tests for the Thesaurus admin controller.
 */
class ThesaurusControllerTest extends AbstractHttpControllerTestCase
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

    /**
     * Test that browse action can be accessed.
     */
    public function testBrowseActionCanBeAccessed(): void
    {
        $this->dispatch('/admin/thesaurus');
        $this->assertControllerName('Thesaurus\Controller\Admin\ThesaurusController');
        $this->assertActionName('browse');
    }

    /**
     * Test that browse action with explicit path can be accessed.
     */
    public function testBrowseExplicitRouteExists(): void
    {
        $this->dispatch('/admin/thesaurus/browse');
        $this->assertControllerName('Thesaurus\Controller\Admin\ThesaurusController');
        $this->assertActionName('browse');
    }

    /**
     * Test that convert action can be accessed.
     */
    public function testConvertActionCanBeAccessed(): void
    {
        $this->dispatch('/admin/thesaurus/convert');
        $this->assertControllerName('Thesaurus\Controller\Admin\ThesaurusController');
        $this->assertActionName('convert');
    }

    /**
     * Test that show action requires a valid item ID.
     */
    public function testShowActionRequiresValidItem(): void
    {
        $this->dispatch('/admin/thesaurus/999999');
        $this->assertResponseStatusCode(404);
    }

    /**
     * Test that structure action requires a valid item ID.
     */
    public function testStructureActionRequiresValidItem(): void
    {
        $this->dispatch('/admin/thesaurus/999999/structure');
        $this->assertResponseStatusCode(404);
    }

    /**
     * Test that flat action redirects without prior conversion.
     */
    public function testFlatActionRedirectsWithoutResult(): void
    {
        $this->dispatch('/admin/thesaurus/flat');
        $this->assertResponseStatusCode(302);
    }

    /**
     * Test that upload action requires POST method.
     */
    public function testUploadActionRequiresPost(): void
    {
        $this->dispatch('/admin/thesaurus/upload');
        $this->assertResponseStatusCode(302);
    }
}
