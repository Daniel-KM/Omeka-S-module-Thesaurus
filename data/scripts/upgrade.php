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

if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.66')) {
    $message = new \Omeka\Stdlib\Message(
        $translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
        'Common', '3.4.66'
    );
    throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
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
    $connection->executeStatement($sql);
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
            'link' => sprintf('<a href="%s">', $url('admin/default', ['controller' => 'setting'], ['fragment' => 'thesaurus'])),
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
