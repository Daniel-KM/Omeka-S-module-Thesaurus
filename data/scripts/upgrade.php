<?php declare(strict_types=1);

namespace Thesaurus;

use Common\Stdlib\PsrMessage;

/**
 * @var Module $this
 * @var \Laminas\ServiceManager\ServiceLocatorInterface $services
 * @var string $newVersion
 * @var string $oldVersion
 *
 * @var \Omeka\Api\Manager $api
 * @var \Omeka\View\Helper\Url $url
 * @var \Omeka\Settings\Settings $settings
 * @var \Doctrine\DBAL\Connection $connection
 * @var \Doctrine\ORM\EntityManager $entityManager
 * @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger
 */
$plugins = $services->get('ControllerPluginManager');
$url = $services->get('ViewHelperManager')->get('url');
$api = $plugins->get('api');
$translate = $plugins->get('translate');
$settings = $services->get('Omeka\Settings');
$connection = $services->get('Omeka\Connection');
$messenger = $plugins->get('messenger');
$entityManager = $services->get('Omeka\EntityManager');

if (PHP_VERSION_ID < 80100) {
    $message = new \Omeka\Stdlib\Message(
        $translate('The module %1$s requires PHP %2$s or later.'), // @translate
        'Thesaurus', '8.1'
    );
    $messenger->addError($message);
    throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
}

if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.91')) {
    $message = new \Omeka\Stdlib\Message(
        $translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
        'Common', '3.4.91'
    );
    $messenger->addError($message);
    throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $translate('Missing requirement. Unable to upgrade.')); // @translate
}

if (version_compare($oldVersion, '3.0.4', '<')) {
    $sql = <<<SQL
        CREATE TABLE term (
            id INT AUTO_INCREMENT NOT NULL,
            item_id INT NOT NULL,
            scheme_id INT NOT NULL,
            root_id INT DEFAULT NULL,
            broader_id INT DEFAULT NULL,
            position INT DEFAULT NULL,
            INDEX IDX_A50FE78D126F525E (item_id),
            INDEX IDX_A50FE78D65797862 (scheme_id),
            INDEX IDX_A50FE78D79066886 (root_id),
            INDEX IDX_A50FE78D5646636A (broader_id),
            UNIQUE INDEX UNIQ_A50FE78D126F525E65797862 (item_id, scheme_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;
        ALTER TABLE term ADD CONSTRAINT FK_A50FE78D126F525E FOREIGN KEY (item_id) REFERENCES item (id) ON DELETE CASCADE;
        ALTER TABLE term ADD CONSTRAINT FK_A50FE78D65797862 FOREIGN KEY (scheme_id) REFERENCES item (id) ON DELETE CASCADE;
        ALTER TABLE term ADD CONSTRAINT FK_A50FE78D79066886 FOREIGN KEY (root_id) REFERENCES term (id) ON DELETE CASCADE;
        ALTER TABLE term ADD CONSTRAINT FK_A50FE78D5646636A FOREIGN KEY (broader_id) REFERENCES term (id) ON DELETE CASCADE;
        SQL;
    foreach (array_filter(array_map('trim', explode(";\n", $sql))) as $sql) {
        $connection->executeStatement($sql);
    }
}

if (version_compare($oldVersion, '3.3.7.0', '<')) {
    $this->storeSchemeAndConceptIds();
}

if (version_compare($oldVersion, '3.3.8.0', '<')) {
    $message = new PsrMessage(
        'It is now possible to get thesaurus data for another item without rebuilding it.' // @translate
    );
    $messenger->addSuccess($message);
}

if (version_compare($oldVersion, '3.4.9', '<')) {
    $settings->set('thesaurus_property_descriptor', 'skos:prefLabel');
    $settings->set('thesaurus_property_path', '');
    $settings->set('thesaurus_property_ascendance', '');
    $settings->set('thesaurus_separator', ' :: ');
    $settings->set('thesaurus_select_display', 'ascendance');

    $message = new PsrMessage(
        'Many performance improvements have been implemented. Big thesaurus can be managed instantly.' // @translate
    );
    $messenger->addSuccess($message);

    $message = new PsrMessage(
        'It is now possible to import a file and to create a standard thesaurus with all linked resources.' // @translate
    );
    $messenger->addSuccess($message);

    $settings->set('easyadmin_interface', ['resource_public_view']);
    $message = new PsrMessage(
        '{link}New settings{link_end} allow to store the path or the ascendance of each concept automatically or via the update button of the thesaurus.', // @translate
        [
            'link' => sprintf('<a href="%s">', htmlspecialchars($url('admin/default', ['controller' => 'setting'], ['fragment' => 'thesaurus']))),
            'link_end' => '</a>',
        ]
    );
    $message->setEscapeHtml(false);
    $messenger->addSuccess($message);
}

if (version_compare($oldVersion, '3.4.16', '<')) {
    $logger = $services->get('Omeka\Logger');
    $blocksRepository = $entityManager->getRepository(\Omeka\Entity\SitePageBlock::class);

    // Replace Block plus template with Omeka layout template.
    $result = [];
    foreach ($blocksRepository->findBy(['layout' => 'thesaurus']) as $block) {
        $data = $block->getData();
        $template = $data['template'] ?? '';
        $layoutData = $block->getLayoutData() ?? [];
        $existingTemplateName = $layoutData['template_name'] ?? null;
        $templateName = pathinfo($template, PATHINFO_FILENAME);
        if ($templateName && $templateName !== 'thesaurus' && (!$existingTemplateName || $existingTemplateName === 'thesaurus')) {
            $layoutData['template_name'] = $templateName;
            $page = $block->getPage();
            $pageSlug = $page->getSlug();
            $result[$page->getSite()->getSlug()][$pageSlug] = $pageSlug;
        }
        unset($data['template']);
        $block->setLayoutData($layoutData);
    }

    $entityManager->flush();

    if ($result) {
        $result = array_map('array_values', $result);
        $message = new PsrMessage(
            'The template layout of some blocks Thesaurus was renamed. Check matching pages: {json}.', // @translate
            ['json' => json_encode($result, 448)]
        );
        $messenger->addWarning($message);
        $logger->warn($message->getMessage(), $message->getContext());
    }
}

if (version_compare($oldVersion, '3.4.17', '<')) {
    /**
     * Migrate blocks of this module to new blocks of Omeka S v4.1.
     *
     * Replace filled settting "heading" by a specific block "Heading".
     * Move setting template to block layout template.
     *
     * @var \Laminas\Log\Logger $logger
     *
     * @see \Omeka\Db\Migrations\MigrateBlockLayoutData
     */
    $logger = $services->get('Omeka\Logger');
    $pageRepository = $entityManager->getRepository(\Omeka\Entity\SitePage::class);
    $blocksRepository = $entityManager->getRepository(\Omeka\Entity\SitePageBlock::class);

    $viewHelpers = $services->get('ViewHelperManager');
    $escape = $viewHelpers->get('escapeHtml');
    $hasBlockPlus = $this->isModuleActive('BlockPlus');

    $pagesUpdated = [];
    $pagesUpdated2 = [];
    foreach ($pageRepository->findAll() as $page) {
        $pageId = $page->getId();
        $pageSlug = $page->getSlug();
        $siteSlug = $page->getSite()->getSlug();
        $position = 0;
        foreach ($page->getBlocks() as $block) {
            $block->setPosition(++$position);
            $layout = $block->getLayout();
            if ($layout !== 'thesaurus') {
                continue;
            }
            $blockId = $block->getId();
            $data = $block->getData() ?: [];

            $heading = $data['heading'] ?? '';
            if (strlen($heading)) {
                $b = new \Omeka\Entity\SitePageBlock();
                $b->setPage($page);
                $b->setPosition(++$position);
                if ($hasBlockPlus) {
                    $b->setLayout('heading');
                    $b->setData([
                        'text' => $heading,
                        'level' => 2,
                    ]);
                } else {
                    $b->setLayout('html');
                    $b->setData([
                        'html' => '<h2>' . $escape($heading) . '</h2>',
                    ]);
                }
                $entityManager->persist($b);
                $block->setPosition(++$position);
                $pagesUpdated[$siteSlug][$pageSlug] = $pageSlug;
            }
            unset($data['heading']);

            $template = $data['template'] ?? null;
            if ($template && $template !== 'common/block-layout/thesaurus') {
                $layoutData = $block->getLayoutData();
                $layoutData['template_name'] = pathinfo($template, PATHINFO_FILENAME);
                $block->setLayoutData($layoutData);
                $pagesUpdated2[$siteSlug][$pageSlug] = $pageSlug;
            }
            unset($data['template']);

            $block->setData($data);
        }
    }

    $entityManager->flush();
    $entityManager->clear();

    if ($pagesUpdated) {
        $result = array_map('array_values', $pagesUpdated);
        $message = new PsrMessage(
            'The setting "heading" was removed from block Thessaurus. New blocks "Heading" or "Html" were prepended to all blocks that had a filled heading. You may check pages for styles: {json}', // @translate
            ['json' => json_encode($result, 448)]
        );
        $messenger->addWarning($message);
        $logger->warn($message->getMessage(), $message->getContext());
    }

    if ($pagesUpdated2) {
        $result = array_map('array_values', $pagesUpdated2);
        $message = new PsrMessage(
            'The setting "template" was moved to the new block layout settings available since Omeka S v4.1. You may check pages for styles: {json}', // @translate
            ['json' => json_encode($result, 448)]
        );
        $messenger->addWarning($message);
        $logger->warn($message->getMessage(), $message->getContext());

        $message = new PsrMessage(
            'The template files for the block Thesaurus should be moved from "view/common/block-layout" to "view/common/block-template" in your themes. You may check your themes for pages: {json}', // @translate
            ['json' => json_encode($result, 448)]
        );
        $messenger->addError($message);
        $logger->warn($message->getMessage(), $message->getContext());
    }
}

if (version_compare($oldVersion, '3.4.24', '<')) {
    // Normalize all literal labels of thesaurus concepts and schemes to Unicode
    // NFC, so identical-looking labels become identical byte-wise (avoids
    // duplicates, failed lookups and broken truncation with decomposed input,
    // typically from macOS or some SKOS/CSV exports).
    $sql = <<<'SQL'
        SELECT v.id, v.value
        FROM value v
        WHERE (v.type IS NULL OR v.type = 'literal')
            AND v.value IS NOT NULL
            AND v.resource_id IN (
                SELECT item_id FROM term
                UNION
                SELECT scheme_id FROM term
            )
        SQL;
    $values = $connection->executeQuery($sql)->fetchAllKeyValue();
    $sqlUpdate = 'UPDATE value SET value = :value WHERE id = :id';
    $count = 0;
    foreach ($values as $id => $value) {
        $normalized = \Normalizer::normalize($value, \Normalizer::FORM_C);
        if ($normalized === false || $normalized === $value) {
            continue;
        }
        $connection->executeStatement($sqlUpdate, ['value' => $normalized, 'id' => (int) $id]);
        ++$count;
    }

    if ($count) {
        $message = new PsrMessage(
            '{count} thesaurus labels were normalized to Unicode NFC.', // @translate
            ['count' => $count]
        );
        $messenger->addSuccess($message);
    }
}

if (version_compare($oldVersion, '3.4.25', '<')) {
    // Rename the table "term" to "thesaurus_term" to use a prefixed name.
    $sql = <<<'SQL'
        SELECT COUNT(*)
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'thesaurus_term'
        SQL;
    $exists = (int) $connection->executeQuery($sql)->fetchOne();
    if (!$exists) {
        $sqls = <<<'SQL'
            RENAME TABLE `term` TO `thesaurus_term`;
            ALTER TABLE `thesaurus_term`
                DROP FOREIGN KEY `FK_A50FE78D126F525E`,
                DROP FOREIGN KEY `FK_A50FE78D65797862`,
                DROP FOREIGN KEY `FK_A50FE78D79066886`,
                DROP FOREIGN KEY `FK_A50FE78D5646636A`;
            ALTER TABLE `thesaurus_term`
                DROP INDEX `IDX_A50FE78D126F525E`,
                DROP INDEX `IDX_A50FE78D65797862`,
                DROP INDEX `IDX_A50FE78D79066886`,
                DROP INDEX `IDX_A50FE78D5646636A`,
                DROP INDEX `UNIQ_A50FE78D126F525E65797862`;
            ALTER TABLE `thesaurus_term`
                ADD INDEX `IDX_C633FC11126F525E` (`item_id`),
                ADD INDEX `IDX_C633FC1165797862` (`scheme_id`),
                ADD INDEX `IDX_C633FC1179066886` (`root_id`),
                ADD INDEX `IDX_C633FC115646636A` (`broader_id`),
                ADD UNIQUE INDEX `UNIQ_C633FC11126F525E65797862` (`item_id`, `scheme_id`);
            ALTER TABLE `thesaurus_term`
                ADD CONSTRAINT `FK_C633FC11126F525E` FOREIGN KEY (`item_id`) REFERENCES `item` (`id`) ON DELETE CASCADE,
                ADD CONSTRAINT `FK_C633FC1165797862` FOREIGN KEY (`scheme_id`) REFERENCES `item` (`id`) ON DELETE CASCADE,
                ADD CONSTRAINT `FK_C633FC1179066886` FOREIGN KEY (`root_id`) REFERENCES `thesaurus_term` (`id`) ON DELETE CASCADE,
                ADD CONSTRAINT `FK_C633FC115646636A` FOREIGN KEY (`broader_id`) REFERENCES `thesaurus_term` (`id`) ON DELETE CASCADE;
            SQL;
        foreach (array_filter(explode(";\n", $sqls)) as $sql) {
            $connection->executeStatement($sql);
        }
        $message = new PsrMessage(
            'The table "term" was renamed to "thesaurus_term".' // @translate
        );
        $messenger->addSuccess($message);
    }

    // The migration of the data types is not run automatically: it is delegated
    // to a job, triggered manually, so the admin can review the impact first.
    $message = new PsrMessage(
        'A new data type "thesaurus" can fill values with a thesaurus concept, without a custom vocab or an item set.' // @translate
    );
    $messenger->addSuccess($message);

    $message = new PsrMessage(
        'To add it to the resource templates (and advanced resource template) that currently use the custom vocab of a thesaurus, run the task "Thesaurus: Add thesaurus data type to custom vocab templates" via the module Easy Admin (Check and fix). The custom vocab is kept for compatibility, and existing values remain valid.' // @translate
    );
    $messenger->addWarning($message);
}

if (version_compare($oldVersion, '3.4.26', '<')) {
    // Create the subtype table for the new resource type "Concept". It is
    // required as soon as the entity is mapped: a query on the abstract
    // resource left joins all subtype tables. The table is filled later, when
    // concepts are migrated from items.
    $sql = <<<'SQL'
        SELECT COUNT(*)
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'concept'
        SQL;
    $exists = (int) $connection->executeQuery($sql)->fetchOne();
    if (!$exists) {
        $sqls = [
            <<<'SQL'
                CREATE TABLE `concept` (
                    `id` INT NOT NULL,
                    `scheme_id` INT NOT NULL,
                    `top_id` INT DEFAULT NULL,
                    `broader_id` INT DEFAULT NULL,
                    `position` INT DEFAULT NULL,
                    INDEX `IDX_E74A605065797862` (`scheme_id`),
                    INDEX `IDX_E74A6050C82CB256` (`top_id`),
                    INDEX `IDX_E74A60505646636A` (`broader_id`),
                    UNIQUE INDEX `UNIQ_E74A6050BF39675065797862` (`id`, `scheme_id`),
                    PRIMARY KEY(`id`)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
                SQL,
            'ALTER TABLE `concept` ADD CONSTRAINT `FK_E74A605065797862` FOREIGN KEY (`scheme_id`) REFERENCES `item` (`id`) ON DELETE CASCADE',
            'ALTER TABLE `concept` ADD CONSTRAINT `FK_E74A6050C82CB256` FOREIGN KEY (`top_id`) REFERENCES `concept` (`id`) ON DELETE CASCADE',
            'ALTER TABLE `concept` ADD CONSTRAINT `FK_E74A60505646636A` FOREIGN KEY (`broader_id`) REFERENCES `concept` (`id`) ON DELETE CASCADE',
            'ALTER TABLE `concept` ADD CONSTRAINT `FK_E74A6050BF396750` FOREIGN KEY (`id`) REFERENCES `resource` (`id`) ON DELETE CASCADE',
        ];
        foreach ($sqls as $sql) {
            $connection->executeStatement($sql);
        }
    }

    // Convert the old index table "thesaurus_term" (concepts stored as items)
    // into the subtype table "concept" (concepts as a dedicated
    // resource type). The resource id is kept, so all linked values remain
    // valid. The broader/root of "thesaurus_term" reference term rows, so they
    // are resolved to their concept id (item_id).
    $sql = <<<'SQL'
        SELECT COUNT(*)
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'thesaurus_term'
        SQL;
    $hasTerm = (int) $connection->executeQuery($sql)->fetchOne();
    if ($hasTerm) {
        // The check of the foreign keys is disabled to move the resources from
        // the table "item" to the table "concept". It is restored in all cases,
        // else the connection would keep it disabled after a failure.
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        $sqls = [
            // The concepts already converted are updated, so the migration
            // can be replayed after a partial upgrade, for example when a big
            // thesaurus exceeds the maximum execution time.
            <<<'SQL'
                INSERT INTO `concept` (`id`, `scheme_id`, `top_id`, `broader_id`, `position`)
                SELECT t.item_id, t.scheme_id, r.item_id, b.item_id, t.position
                FROM `thesaurus_term` t
                LEFT JOIN `thesaurus_term` r ON r.id = t.root_id
                LEFT JOIN `thesaurus_term` b ON b.id = t.broader_id
                ON DUPLICATE KEY UPDATE
                    `scheme_id` = VALUES(`scheme_id`),
                    `top_id` = VALUES(`top_id`),
                    `broader_id` = VALUES(`broader_id`),
                    `position` = VALUES(`position`)
                SQL,
            <<<'SQL'
                UPDATE `resource` SET `resource_type` = 'Thesaurus\\Entity\\Concept'
                WHERE `id` IN (SELECT `item_id` FROM `thesaurus_term`)
                SQL,
            <<<'SQL'
                DELETE FROM `item`
                WHERE `id` IN (SELECT `item_id` FROM (SELECT `item_id` FROM `thesaurus_term`) x)
                SQL,
            // Any value pointing to a concept must use the "resource:concept"
            // data type, so relations from concepts (broader, narrower,
            // related, hasTopConcept) and links to concepts from other
            // resources (dcterms:subject, etc.) are converted from the old item
            // type.
            <<<'SQL'
                UPDATE `value` SET `type` = 'resource:concept'
                WHERE `value_resource_id` IN (SELECT `id` FROM `concept`)
                    AND `type` IN ('resource', 'resource:item')
                SQL,
            'DROP TABLE `thesaurus_term`',
        ];
        try {
            foreach ($sqls as $sql) {
                $connection->executeStatement($sql);
            }
        } finally {
            $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }

        $message = new PsrMessage(
            'The concepts are now a dedicated resource type, no longer mixed with items in browse, search, facets and references.' // @translate
        );
        $messenger->addSuccess($message);
    }

    // The item set of a thesaurus is a standard one now, so the skos classes
    // are useless. They are not removed automatically for now, because an
    // item set may be used elsewhere, but they will be in a future version.
    $sql = <<<'SQL'
        SELECT COUNT(DISTINCT `resource`.`id`)
        FROM `resource`
        INNER JOIN `item_set` ON `item_set`.`id` = `resource`.`id`
        INNER JOIN `resource_class` ON `resource_class`.`id` = `resource`.`resource_class_id`
        INNER JOIN `vocabulary` ON `vocabulary`.`id` = `resource_class`.`vocabulary_id`
        WHERE `vocabulary`.`prefix` = 'skos'
            AND `resource_class`.`local_name` IN ('Collection', 'OrderedCollection')
        SQL;
    $totalCollections = (int) $connection->executeQuery($sql)->fetchOne();
    if ($totalCollections) {
        $message = new PsrMessage(
            'The item set of a thesaurus is a standard one now, identified via the scheme it contains, not via a class. It is recommended to remove the classes "skos:Collection" and "skos:OrderedCollection" from the {count} item sets that use them, because they will be removed automatically in a future version.', // @translate
            ['count' => $totalCollections]
        );
        $messenger->addWarning($message);
    }
}
