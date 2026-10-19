<?php declare(strict_types=1);

namespace ThesaurusTest;

use CommonTest\AbstractHttpControllerTestCase;

/**
 * Tests the restriction of the templates of the thesaurus to the concepts.
 *
 * The templates are restricted only when no item uses them: on a site where the
 * thesaurus are not converted yet, the concepts are items with values of type
 * "resource:item", that would be refused on save.
 */
class TemplateRestrictionTest extends AbstractHttpControllerTestCase
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

    protected function templateId(string $label = 'Thesaurus Concept'): int
    {
        return (int) $this->connection()
            ->executeQuery('SELECT `id` FROM `resource_template` WHERE `label` = ?', [$label])
            ->fetchOne();
    }

    protected function dataTypes(int $templateId, string $term): array
    {
        $dataType = $this->connection()
            ->executeQuery(
                'SELECT `data_type` FROM `resource_template_property` WHERE `resource_template_id` = ? AND `property_id` = ?',
                [$templateId, $this->propertyId($term)]
            )
            ->fetchOne();
        return json_decode((string) $dataType, true) ?: [];
    }

    protected function setDataTypes(int $templateId, string $term, array $dataTypes): void
    {
        $this->connection()->executeStatement(
            'UPDATE `resource_template_property` SET `data_type` = ? WHERE `resource_template_id` = ? AND `property_id` = ?',
            [json_encode($dataTypes), $templateId, $this->propertyId($term)]
        );
    }

    protected function useForResources(int $templateId): ?array
    {
        $hasTable = (int) $this->connection()
            ->executeQuery("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'resource_template_data'")
            ->fetchOne();
        if (!$hasTable) {
            return null;
        }
        $data = $this->connection()
            ->executeQuery('SELECT `data` FROM `resource_template_data` WHERE `resource_template_id` = ?', [$templateId])
            ->fetchOne();
        return (json_decode((string) $data, true) ?: [])['use_for_resources'] ?? null;
    }

    /**
     * Create an item that uses the template of the concepts, like a concept
     * that is not converted yet.
     */
    protected function createItemWithTemplate(int $templateId): int
    {
        $item = $this->createItem([
            'skos:prefLabel' => [['@value' => 'ZZ concept as item']],
        ]);

        // The template may be restricted to the concepts already, like on a
        // site upgraded with a previous version, so it is set directly.
        $this->connection()->executeStatement(
            'UPDATE `resource` SET `resource_template_id` = ? WHERE `id` = ?',
            [$templateId, $item->id()]
        );

        return (int) $item->id();
    }

    /**
     * The upgrade keeps the data type of the items when items use the template.
     */
    public function testDataTypeOfItemsIsKept(): void
    {
        $templateId = $this->templateId();
        $this->assertNotEmpty($templateId);
        $this->createItemWithTemplate($templateId);
        $this->setDataTypes($templateId, 'skos:broader', ['resource:item']);

        $this->runUpgrade('3.4.26', '3.4.27');

        $dataTypes = $this->dataTypes($templateId, 'skos:broader');
        $this->assertContains('resource:item', $dataTypes);
        $this->assertContains('resource:concept', $dataTypes);
        $this->assertNotEquals(['concepts'], $this->useForResources($templateId));
    }

    /**
     * The upgrade restores the data type of the items on a site whose templates
     * were already restricted by a previous version.
     */
    public function testDataTypeOfItemsIsRestored(): void
    {
        $templateId = $this->templateId();
        $this->createItemWithTemplate($templateId);
        $this->setDataTypes($templateId, 'skos:broader', ['resource:concept']);

        $this->runUpgrade('3.4.26', '3.4.27');

        $this->assertContains('resource:item', $this->dataTypes($templateId, 'skos:broader'));
        $this->assertNotEquals(['concepts'], $this->useForResources($templateId));
    }

    /**
     * The template is reserved to the concepts when no item uses it.
     */
    public function testTemplateIsRestrictedWithoutItems(): void
    {
        $templateId = $this->templateId();
        $this->setDataTypes($templateId, 'skos:broader', ['resource:item']);

        $this->runUpgrade('3.4.26', '3.4.27');

        $this->assertEquals(['resource:concept'], $this->dataTypes($templateId, 'skos:broader'));
        $useForResources = $this->useForResources($templateId);
        if ($useForResources !== null) {
            $this->assertEquals(['concepts'], $useForResources);
        }
    }
}
