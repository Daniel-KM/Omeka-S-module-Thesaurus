<?php declare(strict_types=1);

namespace ThesaurusTest\Job;

use CommonTest\AbstractHttpControllerTestCase;
use Thesaurus\Job\ConvertConceptsToItems;
use Thesaurus\Job\ConvertItemsToConcepts;
use ThesaurusTest\ThesaurusTestTrait;

/**
 * Tests the link between a thesaurus and the custom vocab built on its items.
 *
 * A custom vocab contains the items of an item set, so the conversion of the
 * items into concepts empties it: the templates that use it must accept the
 * data type of the thesaurus, else no concept could be selected any more.
 */
class CustomVocabConversionTest extends AbstractHttpControllerTestCase
{
    use ThesaurusTestTrait;

    public function setUp(): void
    {
        parent::setUp();
        if (!class_exists('CustomVocab\Module', false)) {
            $this->markTestSkipped('Requires the module CustomVocab.');
        }
        $this->loginAdmin();
    }

    public function tearDown(): void
    {
        // The properties of the template are removed first: there is no
        // cascade on the template.
        $this->connection()->executeStatement(<<<'SQL'
            DELETE `rtp` FROM `resource_template_property` `rtp`
            INNER JOIN `resource_template` `rt` ON `rt`.`id` = `rtp`.`resource_template_id`
            WHERE `rt`.`label` LIKE 'ZZ template%'
            SQL);
        $this->connection()->executeStatement("DELETE FROM `resource_template` WHERE `label` LIKE 'ZZ template%'");
        $this->cleanupResources();
        $this->cleanupBackups();
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

    protected function dataTypes(int $templatePropertyId): array
    {
        $dataType = $this->connection()
            ->executeQuery('SELECT `data_type` FROM `resource_template_property` WHERE `id` = ?', [$templatePropertyId])
            ->fetchOne();
        return json_decode((string) $dataType, true) ?: [];
    }

    /**
     * Build a thesaurus of items, its item set, its custom vocab and a template
     * property that uses it.
     *
     * @return array Ids of the scheme, of the custom vocab and of the template
     * property.
     */
    protected function createThesaurusWithCustomVocab(): array
    {
        $itemSet = $this->api()->create('item_sets', [
            'dcterms:title' => [[
                'type' => 'literal',
                'property_id' => $this->propertyId('dcterms:title'),
                '@value' => 'ZZ item set ' . uniqid(),
            ]],
        ])->getContent();
        $this->createdResources[] = ['type' => 'item_sets', 'id' => $itemSet->id()];

        $schemeClassId = (int) $this->getServiceLocator()->get('Omeka\Settings')
            ->get('thesaurus_skos_scheme_class_id');
        $scheme = $this->createItem(['dcterms:title' => [['@value' => 'ZZ scheme']]]);
        $this->connection()->executeStatement(
            'UPDATE `resource` SET `resource_class_id` = ? WHERE `id` = ?',
            [$schemeClassId, $scheme->id()]
        );

        $top = $this->createItem(['skos:prefLabel' => [['@value' => 'ZZ racine']]]);
        $this->connection()->executeStatement(
            'INSERT INTO `value` (`resource_id`, `property_id`, `value_resource_id`, `type`, `is_public`) VALUES (?, ?, ?, ?, 1)',
            [$top->id(), $this->propertyId('skos:topConceptOf'), $scheme->id(), 'resource:item']
        );
        foreach ([$scheme->id(), $top->id()] as $itemId) {
            $this->connection()->executeStatement(
                'INSERT INTO `item_item_set` (`item_id`, `item_set_id`) VALUES (?, ?)',
                [$itemId, $itemSet->id()]
            );
        }

        $customVocab = $this->api()->create('custom_vocabs', [
            'o:label' => 'ZZ custom vocab ' . uniqid(),
            'o:item_set' => ['o:id' => $itemSet->id()],
        ])->getContent();
        $this->createdResources[] = ['type' => 'custom_vocabs', 'id' => $customVocab->id()];

        // A template property that uses the custom vocab only.
        $this->connection()->executeStatement(
            'INSERT INTO `resource_template` (`label`) VALUES (?)',
            ['ZZ template ' . uniqid()]
        );
        $templateId = (int) $this->connection()->lastInsertId();
        $this->connection()->executeStatement(
            'INSERT INTO `resource_template_property` (`resource_template_id`, `property_id`, `data_type`, `position`, `is_required`, `is_private`) VALUES (?, ?, ?, 1, 0, 0)',
            [$templateId, $this->propertyId('dcterms:subject'), json_encode(['customvocab:' . $customVocab->id()])]
        );
        $templatePropertyId = (int) $this->connection()->lastInsertId();

        return [(int) $scheme->id(), (int) $customVocab->id(), $templatePropertyId, $templateId];
    }

    /**
     * The template that uses the custom vocab accepts the thesaurus after the
     * conversion, and the custom vocab is kept.
     */
    public function testTemplateOfCustomVocabAcceptsThesaurus(): void
    {
        [$schemeId, $customVocabId, $templatePropertyId] = $this->createThesaurusWithCustomVocab();

        $this->assertEquals(['customvocab:' . $customVocabId], $this->dataTypes($templatePropertyId));

        $this->runJob(ConvertItemsToConcepts::class, ['schemes' => [$schemeId]]);

        $dataTypes = $this->dataTypes($templatePropertyId);
        $this->assertContains('thesaurus:' . $schemeId, $dataTypes, 'The data type of the thesaurus was not added.');
        $this->assertContains('customvocab:' . $customVocabId, $dataTypes, 'The custom vocab was removed.');

        // The custom vocab itself is kept, with its item set.
        $customVocab = $this->api()->read('custom_vocabs', $customVocabId)->getContent();
        $this->assertNotNull($customVocab->itemSet());

    }

    /**
     * The rollback of a thesaurus keeps the changes done for another one.
     *
     * Convert A, then B, then revert A: the template that uses both custom
     * vocabs keeps the data type of B.
     */
    public function testRollbackKeepsOtherThesaurus(): void
    {
        [$schemeA, $customVocabA] = $this->createThesaurusWithCustomVocab();
        [$schemeB, $customVocabB] = $this->createThesaurusWithCustomVocab();

        // A template property that uses both custom vocabs.
        $this->connection()->executeStatement(
            'INSERT INTO `resource_template` (`label`) VALUES (?)',
            ['ZZ template ' . uniqid()]
        );
        $templateId = (int) $this->connection()->lastInsertId();
        $this->connection()->executeStatement(
            'INSERT INTO `resource_template_property` (`resource_template_id`, `property_id`, `data_type`, `position`, `is_required`, `is_private`) VALUES (?, ?, ?, 1, 0, 0)',
            [$templateId, $this->propertyId('dcterms:subject'), json_encode(['customvocab:' . $customVocabA, 'customvocab:' . $customVocabB])]
        );
        $sharedId = (int) $this->connection()->lastInsertId();

        $this->runJob(ConvertItemsToConcepts::class, ['schemes' => [$schemeA]]);
        $dataTypes = $this->dataTypes($sharedId);
        $this->assertContains('thesaurus:' . $schemeA, $dataTypes);
        $this->assertNotContains('thesaurus:' . $schemeB, $dataTypes, 'The other thesaurus was migrated too.');

        $this->runJob(ConvertItemsToConcepts::class, ['schemes' => [$schemeB]]);
        $this->assertContains('thesaurus:' . $schemeB, $this->dataTypes($sharedId));

        $this->runJob(ConvertConceptsToItems::class, ['schemes' => [$schemeA]]);

        $dataTypes = $this->dataTypes($sharedId);
        $this->assertNotContains('thesaurus:' . $schemeA, $dataTypes, 'The data type of the reverted thesaurus was kept.');
        $this->assertContains('thesaurus:' . $schemeB, $dataTypes, 'The data type of the other thesaurus was removed.');
        $this->assertContains('customvocab:' . $customVocabA, $dataTypes);
        $this->assertContains('customvocab:' . $customVocabB, $dataTypes);
    }

    /**
     * The configuration of the template is restored with the rollback.
     */
    public function testTemplateIsRestoredWithRollback(): void
    {
        [$schemeId, $customVocabId, $templatePropertyId] = $this->createThesaurusWithCustomVocab();

        $this->runJob(ConvertItemsToConcepts::class, ['schemes' => [$schemeId]]);
        $this->assertContains('thesaurus:' . $schemeId, $this->dataTypes($templatePropertyId));

        $this->runJob(ConvertConceptsToItems::class, ['schemes' => [$schemeId]]);

        $this->assertEquals(
            ['customvocab:' . $customVocabId],
            $this->dataTypes($templatePropertyId),
            'The configuration of the template was not restored.'
        );

    }
}
