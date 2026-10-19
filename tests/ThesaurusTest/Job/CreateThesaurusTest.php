<?php declare(strict_types=1);

namespace ThesaurusTest\Job;

use CommonTest\AbstractHttpControllerTestCase;
use Thesaurus\Job\CreateThesaurus;
use ThesaurusTest\ThesaurusTestTrait;

/**
 * Tests for the creation of a thesaurus, mainly the identifier, that must be
 * usable in a clean url.
 */
class CreateThesaurusTest extends AbstractHttpControllerTestCase
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
     * The identifier is used in the clean urls, so it must contain only ascii
     * alphanumeric characters, "_" and "-".
     *
     * @dataProvider providerSlugify
     */
    public function testSlugifyKeepsOnlyUrlSafeCharacters(string $input, string $expected): void
    {
        $method = new \ReflectionMethod(CreateThesaurus::class, 'slugify');
        $method->setAccessible(true);
        $job = (new \ReflectionClass(CreateThesaurus::class))->newInstanceWithoutConstructor();

        $result = $method->invoke($job, $input);

        $this->assertSame($expected, $result);
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9][a-zA-Z0-9_-]*$/', $result);
    }

    public function providerSlugify(): array
    {
        return [
            'dot is replaced' => ['test.output', 'test_output'],
            'already safe' => ['thesaurus_caisses_v2', 'thesaurus_caisses_v2'],
            'hyphen is kept' => ['deja-vu', 'deja-vu'],
            'accents are transliterated' => ['Thésaurus été', 'Thesaurus_ete'],
            'spaces and parenthesis' => ['liste (v2).csv', 'liste_v2_csv'],
            'repeated separators are merged' => ['a//b\\c', 'a_b_c'],
            'outer separators are trimmed' => ['  espaces  ', 'espaces'],
        ];
    }

    /**
     * On import, the identifier is slugified, but the label keeps the name.
     */
    public function testImportSlugifiesIdentifierButKeepsLabel(): void
    {
        $scheme = $this->createThesaurus('test.output', ['Europe', "\tFrance"]);

        $this->assertSame('test_output', (string) $scheme->value('dcterms:identifier'));
        $this->assertSame('Test.output', (string) $scheme->value('skos:prefLabel'));
    }

    /**
     * By default, the thesaurus is made of concepts.
     */
    public function testImportCreatesConcepts(): void
    {
        $scheme = $this->createThesaurus('zz concepts', ['Europe', "\tFrance"]);

        $connection = $this->getServiceLocator()->get('Omeka\Connection');
        $total = (int) $connection
            ->executeQuery('SELECT COUNT(*) FROM `concept` WHERE `scheme_id` = ?', [$scheme->id()])
            ->fetchOne();
        $this->assertSame(2, $total);
    }

    /**
     * The concepts may be created as items, in order to expose them with them.
     */
    public function testImportCanCreateItems(): void
    {
        $services = $this->getServiceLocator();

        // The job allows the template of the concepts for the items, but the
        // module Advanced Resource Template caches the data of the templates in
        // a static variable, so a restriction read earlier in the process
        // cannot be invalidated: this is not an issue for a real job, that runs
        // in a new process.
        (new \Thesaurus\Stdlib\ConceptTemplates(
            $services->get('Omeka\Connection'),
            $services->get('Common\EasyMeta'),
            $services->get('Omeka\Settings'),
            $services->get('Omeka\EntityManager')
        ))->allowItems();
        $template = $this->api()
            ->read('resource_templates', ['label' => 'Thesaurus Concept'])
            ->getContent();
        if (method_exists($template, 'dataValue') && $template->dataValue('use_for_resources')) {
            $this->markTestSkipped('The data of the templates are cached by the module Advanced Resource Template.');
        }

        $scheme = $this->createThesaurus('zz items', ['Europe', "\tFrance"], 'items');

        $connection = $services->get('Omeka\Connection');
        $this->assertSame(0, (int) $connection
            ->executeQuery('SELECT COUNT(*) FROM `concept` WHERE `scheme_id` = ?', [$scheme->id()])
            ->fetchOne());

        // The structure is read from the items, so the thesaurus is not empty.
        $thesaurus = $services->build('Thesaurus\Thesaurus');
        $flatTree = $thesaurus($scheme->id())->flatTree();
        $this->assertCount(2, $flatTree);
        foreach (array_keys($flatTree) as $id) {
            $this->assertSame(1, (int) $connection
                ->executeQuery('SELECT COUNT(*) FROM `item` WHERE `id` = ?', [$id])
                ->fetchOne());
        }
    }

    /**
     * The item set of the thesaurus shares the same slugified identifier.
     */
    public function testImportSlugifiesItemSetIdentifier(): void
    {
        $scheme = $this->createThesaurus('test.output', ['Europe']);

        $itemSets = $scheme->itemSets();
        $itemSet = reset($itemSets);
        $this->assertNotEmpty($itemSet, 'The thesaurus should belong to an item set.');
        $this->assertSame('ctest_output', (string) $itemSet->value('dcterms:identifier'));
    }
}
