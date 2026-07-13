<?php declare(strict_types=1);

namespace ThesaurusTest\Controller\Admin;

use CommonTest\AbstractHttpControllerTestCase;
use ThesaurusTest\ThesaurusTestTrait;

/**
 * Tests for the suggest endpoint, that feeds the type-ahead of the data type
 * "thesaurus" in the resource form.
 */
class SuggestActionTest extends AbstractHttpControllerTestCase
{
    use ThesaurusTestTrait;

    /**
     * @var \Omeka\Api\Representation\ItemRepresentation
     */
    protected $scheme;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
        $this->scheme = $this->createThesaurus('suggest_test', [
            'Europe',
            "\tFrance",
            "\t\tParis",
            "\tRoyaume-Uni",
            'Amérique',
        ]);
    }

    public function tearDown(): void
    {
        $this->cleanupResources();
        parent::tearDown();
    }

    /**
     * Dispatch the suggest endpoint as an ajax request and return the results.
     */
    protected function suggest(string $query): array
    {
        $this->dispatch(
            '/admin/thesaurus/' . $this->scheme->id() . '/suggest?q=' . rawurlencode($query),
            'GET',
            [],
            true
        );
        $data = json_decode((string) $this->getResponse()->getContent(), true);
        return $data['results'] ?? [];
    }

    /**
     * The endpoint is only for ajax, to avoid to expose a browsable page.
     */
    public function testSuggestRequiresAjax(): void
    {
        $this->dispatch('/admin/thesaurus/' . $this->scheme->id() . '/suggest?q=paris');
        $this->assertResponseStatusCode(404);
    }

    /**
     * A concept is returned with its ascendance, to disambiguate homonyms.
     */
    public function testSuggestReturnsConceptWithAscendance(): void
    {
        $results = $this->suggest('paris');

        $this->assertCount(1, $results);
        $this->assertSame('Paris', $results[0]['title']);
        $this->assertSame('Europe › France', $results[0]['ascendance']);
        $this->assertGreaterThan(0, (int) $results[0]['id']);
    }

    /**
     * The search ignores the case and the diacritics.
     *
     * @dataProvider providerQueries
     */
    public function testSuggestIgnoresCaseAndDiacritics(string $query, string $expected): void
    {
        $results = $this->suggest($query);

        $titles = array_column($results, 'title');
        $this->assertContains($expected, $titles);
    }

    public function providerQueries(): array
    {
        return [
            'lower case' => ['paris', 'Paris'],
            'upper case' => ['PARIS', 'Paris'],
            'partial' => ['par', 'Paris'],
            'without diacritic' => ['amerique', 'Amérique'],
            'with diacritic' => ['Amérique', 'Amérique'],
        ];
    }

    /**
     * An empty query returns the head of the tree, so the widget can behave
     * like a select for a small thesaurus.
     */
    public function testSuggestWithEmptyQueryReturnsTree(): void
    {
        $results = $this->suggest('');

        $titles = array_column($results, 'title');
        $this->assertContains('Europe', $titles);
        $this->assertContains('Paris', $titles);
        $this->assertSame('Europe', $titles[0], 'The tree order should be kept.');
    }

    /**
     * A query without match returns an empty list, not an error.
     */
    public function testSuggestWithoutMatchReturnsEmptyList(): void
    {
        $this->assertSame([], $this->suggest('zzz_no_match'));
    }

    /**
     * The suggestions are limited to the concepts of the given thesaurus.
     */
    public function testSuggestIsScopedToScheme(): void
    {
        $other = $this->createThesaurus('suggest_other', ['Asie', "\tJapon"]);

        $results = $this->suggest('japon');
        $this->assertSame([], $results, 'A concept of another thesaurus should not be suggested.');

        $this->assertNotEmpty($other->id());
    }
}
