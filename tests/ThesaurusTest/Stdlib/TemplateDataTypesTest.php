<?php declare(strict_types=1);

namespace ThesaurusTest\Stdlib;

use CommonTest\AbstractHttpControllerTestCase;
use Thesaurus\Stdlib\TemplateDataTypes;
use ThesaurusTest\ThesaurusTestTrait;

/**
 * Tests the addition of the thesaurus data types to the resource templates.
 */
class TemplateDataTypesTest extends AbstractHttpControllerTestCase
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

    /**
     * Create an item with a template restricted to the data types and a value.
     *
     * @return array The id of the template and the id of the property.
     */
    protected function createItemWithValue(?array $dataTypes, string $valueType, int $valueResourceId): array
    {
        $propertyId = $this->propertyId('dcterms:subject');
        $template = $this->api()->create('resource_templates', [
            'o:label' => 'ZZ template ' . uniqid(),
            'o:resource_template_property' => [
                ['o:property' => ['o:id' => $propertyId]],
            ],
        ])->getContent();
        $this->createdResources[] = ['type' => 'resource_templates', 'id' => $template->id()];

        $this->connection()->executeStatement(
            'UPDATE `resource_template_property` SET `data_type` = ? WHERE `resource_template_id` = ? AND `property_id` = ?',
            [$dataTypes === null ? null : json_encode($dataTypes), $template->id(), $propertyId]
        );
        $item = $this->createItem(['dcterms:title' => [['@value' => 'ZZ item']]]);
        $this->connection()->executeStatement(
            'UPDATE `resource` SET `resource_template_id` = ? WHERE `id` = ?',
            [$template->id(), $item->id()]
        );
        $this->connection()->executeStatement(
            'INSERT INTO `value` (`resource_id`, `property_id`, `value_resource_id`, `type`, `is_public`) VALUES (?, ?, ?, ?, 1)',
            [$item->id(), $propertyId, $valueResourceId, $valueType]
        );

        return [$template->id(), $propertyId];
    }

    protected function dataTypes(int $templateId, int $propertyId): ?array
    {
        $dataType = $this->connection()
            ->executeQuery(
                'SELECT `data_type` FROM `resource_template_property` WHERE `resource_template_id` = ? AND `property_id` = ?',
                [$templateId, $propertyId]
            )
            ->fetchOne();
        return $dataType === null ? null : json_decode((string) $dataType, true);
    }

    /**
     * A concept may be selected via another custom vocab than the one of its
     * thesaurus, for example an index of authorities.
     */
    public function testRestrictedTemplateAllowsTheThesaurus(): void
    {
        $scheme = $this->createItem(['dcterms:title' => [['@value' => 'ZZ scheme']]]);
        [$templateId, $propertyId] = $this->createItemWithValue(['literal', 'customvocab:1'], 'thesaurus:' . $scheme->id(), $scheme->id());

        $templateDataTypes = new TemplateDataTypes($this->connection());
        $this->assertGreaterThanOrEqual(1, $templateDataTypes->allowUsedThesaurusTypes());

        $expected = ['literal', 'customvocab:1', 'thesaurus:' . $scheme->id()];
        $this->assertEquals($expected, $this->dataTypes($templateId, $propertyId));

        // The process can be replayed without changing anything.
        $templateDataTypes->allowUsedThesaurusTypes();
        $this->assertEquals($expected, $this->dataTypes($templateId, $propertyId));
    }

    /**
     * A template property without data type allows all of them.
     */
    public function testUnrestrictedTemplateIsKept(): void
    {
        $scheme = $this->createItem(['dcterms:title' => [['@value' => 'ZZ scheme']]]);
        [$templateId, $propertyId] = $this->createItemWithValue(null, 'thesaurus:' . $scheme->id(), $scheme->id());

        (new TemplateDataTypes($this->connection()))->allowUsedThesaurusTypes();

        $this->assertNull($this->dataTypes($templateId, $propertyId));
    }
}
