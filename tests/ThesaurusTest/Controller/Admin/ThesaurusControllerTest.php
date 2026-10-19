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

    /**
     * The sidebar of the rollback says that there is no backup.
     */
    public function testRollbackSidebarWithoutBackup(): void
    {
        $this->cleanupBackups();
        $this->dispatch('/admin/thesaurus/maintenance-sidebar?task=convert-concepts-to-items');
        $this->assertResponseStatusCode(200);
        $this->assertStringContainsString('There is no backup', $this->getResponse()->getContent());
    }

    /**
     * The sidebar of the rollback displays the date of the last backup and says
     * that the current data are kept.
     */
    public function testRollbackSidebarWithBackup(): void
    {
        $scheme = $this->createItem(['dcterms:title' => [['@value' => 'ZZ scheme']]]);
        $connection = $this->getServiceLocator()->get('Omeka\Connection');
        $backupId = (new \Thesaurus\Stdlib\ReferencesBackup($connection))
            ->save([(int) $scheme->id()], ['item_site' => ['column' => 'item_id', 'rows' => [['item_id' => 1, 'site_id' => 1]]]]);
        $created = (string) $connection
            ->executeQuery('SELECT `created` FROM `thesaurus_backup` WHERE `id` = ?', [$backupId])
            ->fetchOne();

        $this->dispatch('/admin/thesaurus/maintenance-sidebar?task=convert-concepts-to-items');

        $content = $this->getResponse()->getContent();
        $this->assertStringContainsString('The current data are kept', $content);
        $this->assertStringContainsString('#' . $backupId . ', ' . $created, $content);
        $this->cleanupBackups();
    }
}
