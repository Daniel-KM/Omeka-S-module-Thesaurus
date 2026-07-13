<?php declare(strict_types=1);

namespace ThesaurusTest\DataType;

use CommonTest\AbstractHttpControllerTestCase;
use ThesaurusTest\ThesaurusTestTrait;

/**
 * Tests for the data type "thesaurus:{schemeId}", that fills a value with a
 * concept without the need of a custom vocab or an item set.
 */
class ThesaurusDataTypeTest extends AbstractHttpControllerTestCase
{
    use ThesaurusTestTrait;

    /**
     * @var \Omeka\Api\Representation\ItemRepresentation
     */
    protected $scheme;

    /**
     * @var string
     */
    protected $dataTypeName;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
        $this->scheme = $this->createThesaurus('datatype_test', ['Europe', "\tFrance"]);
        $this->dataTypeName = 'thesaurus:' . $this->scheme->id();
    }

    public function tearDown(): void
    {
        $this->cleanupResources();
        parent::tearDown();
    }

    protected function dataTypeManager(): \Omeka\DataType\Manager
    {
        return $this->getServiceLocator()->get('Omeka\DataTypeManager');
    }

    /**
     * A data type is available for each thesaurus, built by the abstract
     * factory from the id of the scheme.
     */
    public function testDataTypeExistsForScheme(): void
    {
        $manager = $this->dataTypeManager();

        $this->assertTrue($manager->has($this->dataTypeName));
        $this->assertSame($this->dataTypeName, $manager->get($this->dataTypeName)->getName());
    }

    /**
     * The data type is listed, so it can be selected in a resource template.
     */
    public function testDataTypeIsRegistered(): void
    {
        $this->assertContains($this->dataTypeName, $this->dataTypeManager()->getRegisteredNames());
    }

    /**
     * The label is the title of the thesaurus, inside a common optgroup.
     */
    public function testDataTypeLabels(): void
    {
        $dataType = $this->dataTypeManager()->get($this->dataTypeName);

        $this->assertSame('Datatype_test', $dataType->getLabel());
        $this->assertSame('Thesaurus', $dataType->getOptgroupLabel());
    }

    /**
     * There is no data type for an item that is not a thesaurus.
     */
    public function testNoDataTypeForNonScheme(): void
    {
        $item = $this->createItem(['dcterms:title' => [['@value' => 'Not a thesaurus']]]);

        $this->assertFalse($this->dataTypeManager()->has('thesaurus:' . $item->id()));
    }

    /**
     * The value is stored as a linked resource, so only a resource id is valid.
     *
     * @dataProvider providerValues
     */
    public function testIsValid(array $valueObject, bool $expected): void
    {
        $dataType = $this->dataTypeManager()->get($this->dataTypeName);

        $this->assertSame($expected, $dataType->isValid($valueObject));
    }

    public function providerValues(): array
    {
        return [
            'resource id' => [['value_resource_id' => 1], true],
            'resource id as string' => [['value_resource_id' => '1'], true],
            'no value' => [[], false],
            'empty value' => [['value_resource_id' => null], false],
            'not a number' => [['value_resource_id' => 'abc'], false],
            'literal value' => [['@value' => 'Europe'], false],
        ];
    }

    /**
     * The data type can be used to annotate a value.
     */
    public function testDataTypeIsAvailableForValueAnnotation(): void
    {
        $dataTypes = $this->getServiceLocator()->get('ViewHelperManager')
            ->get('dataType')->getValueAnnotationDataTypes();

        $this->assertArrayHasKey($this->dataTypeName, $dataTypes);
    }
}
