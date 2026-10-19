<?php declare(strict_types=1);

/**
 * Bootstrap file for module tests.
 *
 * Use Common module Bootstrap helper for test setup.
 */

require dirname(__DIR__, 3) . '/modules/Common/tests/Bootstrap.php';

\CommonTest\Bootstrap::bootstrap(
    [
        'Common',
        // Optional: installed first, because the thesaurus sets its options on
        // the templates when its tables exist.
        '?AdvancedResourceTemplate',
        '?CustomVocab',
        'Thesaurus',
        // Optional: when the module is active, its entities are mapped, so its
        // tables are required as soon as a resource is serialized.
        '?DigitalObject',
    ],
    'ThesaurusTest',
    __DIR__ . '/ThesaurusTest'
);
