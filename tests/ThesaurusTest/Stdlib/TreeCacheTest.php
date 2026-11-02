<?php declare(strict_types=1);

namespace ThesaurusTest\Stdlib;

use CommonTest\AbstractHttpControllerTestCase;
use Thesaurus\Job\ConvertConceptsToItems;
use Thesaurus\Job\ConvertItemsToConcepts;
use Thesaurus\Job\IndexThesaurus;
use Thesaurus\Stdlib\RelationsCache;
use ThesaurusTest\ThesaurusTestTrait;

/**
 * Tests the persistent cache of the relations used to build the whole tree.
 */
class TreeCacheTest extends AbstractHttpControllerTestCase
{
    use ThesaurusTestTrait;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
        $this->cache()->clear();
    }

    public function tearDown(): void
    {
        $this->cache()->clear();
        $this->cleanupBackups();
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

    protected function cache(): RelationsCache
    {
        return new RelationsCache($this->connection());
    }

    protected function thesaurus(): \Thesaurus\Stdlib\Thesaurus
    {
        return $this->getServiceLocator()->build('Thesaurus\Thesaurus');
    }

    protected function isCached(int $schemeId): bool
    {
        return $this->cache()->read($schemeId) !== null;
    }

    protected function addValue(int $resourceId, string $term, int $valueResourceId, bool $isPublic = true): void
    {
        $this->api()->update('items', $resourceId, [
            $term => [[
                'type' => 'resource:item',
                'property_id' => $this->propertyId($term),
                'value_resource_id' => $valueResourceId,
                'is_public' => $isPublic,
            ]],
        ], [], ['isPartial' => true, 'collectionAction' => 'append']);
    }

    /**
     * Build a thesaurus of items: one top, three children.
     *
     * @return array Ids of the scheme, the top and the children.
     */
    protected function createThesaurusOfItems(): array
    {
        $scheme = (int) $this->createItem(['dcterms:title' => [['@value' => 'ZZ scheme']]])->id();
        $top = (int) $this->createItem(['skos:prefLabel' => [['@value' => 'ZZ top']]])->id();
        $this->addValue($top, 'skos:topConceptOf', $scheme);
        $this->addValue($scheme, 'skos:hasTopConcept', $top);
        $children = [];
        foreach (['a', 'b', 'c'] as $label) {
            $child = (int) $this->createItem(['skos:prefLabel' => [['@value' => "ZZ $label"]]])->id();
            $this->addValue($child, 'skos:inScheme', $scheme);
            $this->addValue($child, 'skos:broader', $top);
            $this->addValue($top, 'skos:narrower', $child);
            $children[] = $child;
        }
        return [$scheme, $top, $children];
    }

    /**
     * The cache is built with the whole tree and gives the same tree.
     */
    public function testCacheIsBuiltAndGivesTheSameTree(): void
    {
        [$scheme, $top, $children] = $this->createThesaurusOfItems();
        $this->cache()->clear();

        $first = $this->thesaurus()($scheme)->flatTree();
        $this->assertTrue($this->isCached($scheme), 'The cache was not built.');
        $second = $this->thesaurus()($scheme)->flatTree();

        $this->assertSame(json_encode($first), json_encode($second));
        $this->assertSame([$top, ...$children], array_keys($second));
    }

    /**
     * The partial trees do not need the cache.
     */
    public function testPartialTreeDoesNotBuildTheCache(): void
    {
        [$scheme, $top] = $this->createThesaurusOfItems();
        $this->cache()->clear();

        $this->thesaurus()($top)->descendants();
        $this->assertFalse($this->isCached($scheme));
    }

    /**
     * The relations with a private value are not used for the visitors.
     */
    public function testPrivateRelationsAreSkippedForVisitors(): void
    {
        [$scheme, $top, $children] = $this->createThesaurusOfItems();
        $private = (int) $this->createItem(['skos:prefLabel' => [['@value' => 'ZZ private']]])->id();
        $this->addValue($private, 'skos:inScheme', $scheme);
        $this->addValue($private, 'skos:broader', $top, false);

        $thesaurus = $this->thesaurus();
        $thesaurus($scheme)->flatTree();
        $this->assertTrue($this->isCached($scheme));

        $relations = new \ReflectionMethod($thesaurus, 'wholeRelationsOf');
        $relations->setAccessible(true);
        $concepts = [$top, ...$children, $private];

        // An administrator gets all the relations.
        [$parents] = $relations->invoke($thesaurus, $concepts);
        $this->assertSame($top, $parents[$private] ?? null);

        // A visitor does not get the private relation.
        $this->getServiceLocator()->get('Omeka\AuthenticationService')->clearIdentity();
        [$parents, $children2] = $relations->invoke($thesaurus, $concepts);
        $this->assertArrayNotHasKey($private, $parents);
        $this->assertSame($children, $children2[$top]);
        $this->loginAdmin();
    }

    /**
     * The cache of a thesaurus is removed when one of its concepts changes.
     */
    public function testCacheIsRemovedOnUpdate(): void
    {
        [$scheme, $top, $children] = $this->createThesaurusOfItems();

        $this->thesaurus()($scheme)->flatTree();
        $this->assertTrue($this->isCached($scheme));
        $this->addValue($children[0], 'skos:broader', $children[1]);
        $this->assertFalse($this->isCached($scheme), 'The cache was not removed on update.');

        // A concept related to the top with its own value only, so no value of
        // another resource references it in memory.
        $leaf = (int) $this->createItem(['skos:prefLabel' => [['@value' => 'ZZ leaf']]])->id();
        $this->addValue($leaf, 'skos:inScheme', $scheme);
        $this->addValue($leaf, 'skos:broader', $top);
        $this->thesaurus()($scheme)->flatTree();
        $this->assertTrue($this->isCached($scheme));
        $this->api()->delete('items', $leaf);
        $this->assertFalse($this->isCached($scheme), 'The cache was not removed on deletion.');

        $this->thesaurus()($scheme)->flatTree();
        $this->assertTrue($this->isCached($scheme));
        $new = (int) $this->createItem(['skos:prefLabel' => [['@value' => 'ZZ new']]])->id();
        $this->addValue($new, 'skos:inScheme', $scheme);
        $this->assertFalse($this->isCached($scheme), 'The cache was not removed on creation.');
    }

    /**
     * Another thesaurus keeps its cache.
     */
    public function testOtherThesaurusKeepsItsCache(): void
    {
        [$schemeA, , $childrenA] = $this->createThesaurusOfItems();
        [$schemeB] = $this->createThesaurusOfItems();
        $this->thesaurus()($schemeA)->flatTree();
        $this->thesaurus()($schemeB)->flatTree();

        $this->addValue($childrenA[0], 'skos:broader', $childrenA[1]);

        $this->assertFalse($this->isCached($schemeA));
        $this->assertTrue($this->isCached($schemeB));
    }

    /**
     * The jobs that convert or index a thesaurus remove its cache.
     */
    public function testJobsRemoveTheCache(): void
    {
        [$scheme] = $this->createThesaurusOfItems();

        $this->thesaurus()($scheme)->flatTree();
        $this->runJob(ConvertItemsToConcepts::class, ['schemes' => [$scheme]]);
        $tree = $this->thesaurus()($scheme)->flatTree();
        $this->assertCount(4, $tree, 'The tree of the converted thesaurus is wrong.');

        $this->thesaurus()($scheme)->flatTree();
        $this->assertTrue($this->isCached($scheme));
        $this->runJob(IndexThesaurus::class, ['scheme' => $scheme]);
        $this->runJob(ConvertConceptsToItems::class, ['schemes' => [$scheme]]);
        $this->assertFalse($this->isCached($scheme), 'The cache was not removed by the conversion into items.');
    }

    /**
     * An outdated cache is ignored and rebuilt on the next display.
     */
    public function testOutdatedCacheIsRebuilt(): void
    {
        [$scheme, $top, $children] = $this->createThesaurusOfItems();
        $this->thesaurus()($scheme)->flatTree();
        $this->assertTrue($this->isCached($scheme));

        // A relation changed directly in the database is not detected, but the
        // cache expires.
        $this->connection()->executeStatement(
            'UPDATE `value` SET `value_resource_id` = ? WHERE `resource_id` = ? AND `property_id` = ?',
            [$children[1], $children[0], $this->propertyId('skos:broader')]
        );
        $this->connection()->executeStatement(
            'UPDATE `thesaurus_tree_cache` SET `created` = ? WHERE `scheme_id` = ?',
            [(new \DateTime('-' . (RelationsCache::LIFETIME + 3600) . ' seconds'))->format('Y-m-d H:i:s'), $scheme]
        );
        $this->assertFalse($this->isCached($scheme), 'The outdated cache was used.');

        $this->getServiceLocator()->get('Omeka\EntityManager')->clear();
        $this->loginAdmin();
        $tree = $this->thesaurus()($scheme)->flatTree();
        $this->assertTrue($this->isCached($scheme), 'The cache was not rebuilt.');
        $this->assertSame($children[1], $tree[$children[0]]['self']['parent']);
    }

    /**
     * The upgrade creates the table of the cache.
     */
    public function testUpgradeCreatesTheTable(): void
    {
        $hasKey = fn () => (int) $this->connection()->executeQuery(<<<'SQL'
            SELECT COUNT(*) FROM `information_schema`.`TABLE_CONSTRAINTS`
            WHERE `CONSTRAINT_SCHEMA` = DATABASE()
                AND `TABLE_NAME` = 'thesaurus_tree_cache'
                AND `CONSTRAINT_NAME` = 'FK_74BD0F4965797862'
            SQL)->fetchOne();

        $this->connection()->executeStatement('DROP TABLE `thesaurus_tree_cache`');
        $this->runUpgrade('3.4.27', '3.4.28');
        $this->assertNotFalse($this->connection()->executeQuery("SHOW TABLES LIKE 'thesaurus_tree_cache'")->fetchOne());
        $this->assertSame(1, $hasKey());

        // The block can be replayed without error.
        $this->runUpgrade('3.4.27', '3.4.28');
        $this->assertSame(1, $hasKey());
    }
}
