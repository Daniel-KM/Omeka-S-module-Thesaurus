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
