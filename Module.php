<?php declare(strict_types=1);

namespace Thesaurus;

if (!trait_exists(\Common\TraitModule::class, false)) {
    if (file_exists(OMEKA_PATH . '/modules/Common/src/TraitModule.php')) {
        require_once OMEKA_PATH . '/modules/Common/src/TraitModule.php';
    } elseif (file_exists(OMEKA_PATH . '/composer-addons/modules/Common/src/TraitModule.php')) {
        require_once OMEKA_PATH . '/composer-addons/modules/Common/src/TraitModule.php';
    } elseif (file_exists(dirname(__DIR__) . '/Common/src/TraitModule.php')) {
        require_once dirname(__DIR__) . '/Common/src/TraitModule.php';
    }
}

use Common\Stdlib\PsrMessage;
use Common\TraitModule;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Query\Expr\Join;
use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Mvc\MvcEvent;
use Omeka\Module\AbstractModule;

/**
 * Thesaurus
 *
 * Allows to use standard thesaurus (ISO 25964 to describe documents).
 *
 * @copyright Daniel Berthereau, 2018-2026
 * @license http://www.cecill.info/licences/Licence_CeCILL_V2.1-en.txt
 */
class Module extends AbstractModule
{
    use TraitModule;

    const NAMESPACE = __NAMESPACE__;

    const SEPARATOR = ' :: ';

    /**
     * Warn only once by request that a thesaurus should be reindexed.
     *
     * @var bool
     */
    protected $isStructureWarned = false;

    /**
     * Warn only once by request that the concepts follow their scheme.
     *
     * @var bool
     */
    protected $isDeleteWarned = false;

    /**
     * Warn that the concepts are converted back into items on uninstall.
     *
     * The checkbox is checked by default, because the concepts cannot be kept:
     * the table is removed with the module.
     *
     * @see \DigitalObject\Module::warnUninstall()
     */
    public function warnUninstall(Event $event): void
    {
        $view = $event->getTarget();
        $module = $view->vars()->module;
        if ($module->getId() !== __NAMESPACE__) {
            return;
        }

        $totalConcepts = $this->totalConcepts();
        if (!$totalConcepts) {
            return;
        }

        $escape = $view->plugin('escapeHtml');
        $translator = $this->getServiceLocator()->get('MvcTranslator');

        $html = '<p style="color:#c00"><strong>'
            . $escape($translator->translate('WARNING')) // @translate
            . '</strong>: ';
        $html .= $escape(sprintf(
            $translator->translate('%d concepts still exist. They are a specific resource, so they cannot be kept once the module is removed: choose to convert them into items or to delete them.'), // @translate
            $totalConcepts
        ));
        $html .= '</p>';
        $html .= '<label><input name="thesaurus-uninstall-mode" type="radio" form="confirmform" value="convert" checked="checked"> ';
        $html .= $escape($translator->translate('Convert the concepts back into items. The values that point to them are kept, but the thesaurus structure is lost.')); // @translate
        $html .= '</label><br/>';
        $html .= '<label><input name="thesaurus-uninstall-mode" type="radio" form="confirmform" value="delete"> ';
        $html .= $escape($translator->translate('Delete all the concepts, with their values and the values that point to them.')); // @translate
        $html .= '</label>';

        echo $html;
    }

    /**
     * Get the total of concepts, or zero when the table does not exist.
     */
    protected function totalConcepts(): int
    {
        /** @var \Doctrine\DBAL\Connection $connection */
        $connection = $this->getServiceLocator()->get('Omeka\Connection');

        $hasConcept = (int) $connection
            ->executeQuery(<<<'SQL'
                SELECT COUNT(*)
                FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = 'concept'
                SQL)
            ->fetchOne();

        return $hasConcept
            ? (int) $connection->executeQuery('SELECT COUNT(*) FROM `concept`')->fetchOne()
            : 0;
    }

    /**
     * Delete the concepts of a scheme before the scheme is deleted.
     *
     * The foreign key of the column "scheme_id" removes the rows of the table
     * "concept" by cascade, but not the rows of the table "resource", that
     * would remain without their subtype row. So the concepts are deleted
     * first, with the orm.
     */
    public function deleteSchemeConcepts(Event $event): void
    {
        $id = (int) $event->getParam('request')->getId();
        if (!$id) {
            return;
        }

        $services = $this->getServiceLocator();
        $messenger = $services->get('ControllerPluginManager')->get('messenger');

        // The deletion is the default, like the cascade of the foreign key. The
        // conversion is done only when it is explicitly asked in the sidebar of
        // confirmation.
        $convert = $services->get('Request')->getPost('thesaurus-scheme-delete-mode') === 'convert';
        if ($convert) {
            $total = $this->convertConceptsToItems($id);
            if ($total) {
                $messenger->addWarning(new PsrMessage(
                    'The {count} concepts of the thesaurus were converted into items, so they are kept without their structure.', // @translate
                    ['count' => $total]
                ));
            }
            return;
        }

        $total = $this->deleteConcepts($id);
        if (!$total) {
            return;
        }

        $messenger->addWarning(new PsrMessage(
            'The {count} concepts of the thesaurus were deleted with it.', // @translate
            ['count' => $total]
        ));
    }

    /**
     * Warn that the concepts of a thesaurus are removed with their scheme.
     *
     * The sidebar of confirmation of the deletion of an item includes the
     * partial of the details, that triggers the event "view.details".
     *
     * @see \Omeka\Controller\Admin\ItemController::deleteConfirmAction()
     */
    public function warnDeleteScheme(Event $event): void
    {
        $view = $event->getTarget();

        // The event "view.details" is triggered by the sidebar of the details
        // too, where the question is not asked, but not by the page of edition,
        // that triggers "view.delete.confirm" with another key.
        $resource = $event->getParam('resource');
        if ($resource) {
            $isDeleteConfirm = true;
        } else {
            $resource = $event->getParam('entity');
            $isDeleteConfirm = $view->params()->fromRoute('action') === 'delete-confirm';
        }

        if (!$isDeleteConfirm || !$resource || $resource->resourceName() !== 'items') {
            return;
        }

        // The sidebar of the page of edition is displayed for any item, but the
        // markup is output only once.
        if ($this->isDeleteWarned) {
            return;
        }
        $this->isDeleteWarned = true;

        /** @var \Doctrine\DBAL\Connection $connection */
        $connection = $this->getServiceLocator()->get('Omeka\Connection');
        $totalConcepts = (int) $connection
            ->executeQuery('SELECT COUNT(*) FROM `concept` WHERE `scheme_id` = ?', [$resource->id()])
            ->fetchOne();
        if (!$totalConcepts) {
            return;
        }

        $escape = $view->plugin('escapeHtml');
        $translator = $this->getServiceLocator()->get('MvcTranslator');

        $html = '<p style="color:#c00"><strong>'
            . $escape($translator->translate('WARNING')) // @translate
            . '</strong>: ';
        $html .= $escape(sprintf(
            $translator->translate('This item is a thesaurus scheme with %d concepts. A concept cannot exist without its scheme.'), // @translate
            $totalConcepts
        ));
        $html .= '</p>';
        $html .= '<label><input name="thesaurus-scheme-delete-mode" type="radio" form="confirmform" value="delete" checked="checked"> ';
        $html .= $escape($translator->translate('Delete the concepts, with their values and the values that point to them.')); // @translate
        $html .= '</label><br/>';
        $html .= '<label><input name="thesaurus-scheme-delete-mode" type="radio" form="confirmform" value="convert"> ';
        $html .= $escape($translator->translate('Convert the concepts into items. The values that point to them are kept, but the thesaurus structure is lost.')); // @translate
        $html .= '</label>';

        echo $html;
    }

    /**
     * Delete concepts through the orm, so the values are removed too.
     *
     * The rows of the table "resource" are removed, not only the rows of the
     * table "concept": a resource without its subtype row would break any query
     * on resources. The database removes the values of the concepts and the
     * values that point to them by cascade.
     *
     * @param int|null $schemeId Limit the deletion to the concepts of a scheme.
     * @return int The number of deleted concepts.
     */
    protected function deleteConcepts(?int $schemeId = null): int
    {
        $services = $this->getServiceLocator();

        /** @var \Doctrine\DBAL\Connection $connection */
        $connection = $services->get('Omeka\Connection');
        /** @var \Doctrine\ORM\EntityManager $entityManager */
        $entityManager = $services->get('Omeka\EntityManager');

        $ids = $schemeId
            ? $connection->executeQuery('SELECT `id` FROM `concept` WHERE `scheme_id` = ?', [$schemeId])->fetchFirstColumn()
            : $connection->executeQuery('SELECT `id` FROM `concept`')->fetchFirstColumn();
        if (!$ids) {
            return 0;
        }

        // The resource is removed, not the concept: the deletion of a concept
        // removes the rows of its descendants in the table "concept" by
        // cascade, so they may be already gone here, but their resource is
        // still to remove.
        $total = 0;
        foreach (array_chunk(array_map('intval', $ids), 100) as $chunk) {
            foreach ($chunk as $id) {
                $resource = $entityManager->find(\Omeka\Entity\Resource::class, $id);
                if ($resource) {
                    $entityManager->remove($resource);
                    ++$total;
                }
            }
            // The entity manager is not cleared: the deletion may occur inside
            // a batch delete of the core, that keeps track of the entities it
            // manages.
            $entityManager->flush();
        }

        return $total;
    }

    /**
     * Convert the concepts back into items before the table is removed.
     *
     * The concepts are a subtype of resource, so the rows of the table
     * "resource" would keep a discriminator without table and without mapping,
     * breaking any query on resources. The ids are kept, so all values that
     * point to a concept remain valid.
     */
    protected function preUninstall(): void
    {
        $services = $this->getServiceLocator();

        $totalConcepts = $this->totalConcepts();
        if (!$totalConcepts) {
            return;
        }

        // The conversion is the default, so a command line uninstall keeps the
        // resources. The deletion is done only when it is explicitly asked.
        $request = $services->get('Request');
        if ($request->getPost('thesaurus-uninstall-mode') === 'delete') {
            $totalDeleted = $this->deleteConcepts();
            $message = new PsrMessage(
                'The {count} concepts were deleted, with their values and the values that pointed to them.', // @translate
                ['count' => $totalDeleted]
            );
            $services->get('ControllerPluginManager')->get('messenger')->addWarning($message);
            return;
        }

        $this->convertConceptsToItems();

        $message = new PsrMessage(
            'The {count} concepts were converted back into items, so they are no longer structured as a thesaurus.', // @translate
            ['count' => $totalConcepts]
        );
        $services->get('ControllerPluginManager')->get('messenger')->addWarning($message);
    }

    /**
     * Convert concepts into items, keeping their ids, values and links.
     *
     * @param int|null $schemeId Limit the conversion to the concepts of a
     *   scheme. The rows of the table "concept" are removed in that case, since
     *   the table is kept.
     * @return int The number of converted concepts.
     */
    protected function convertConceptsToItems(?int $schemeId = null): int
    {
        /** @var \Doctrine\DBAL\Connection $connection */
        $connection = $this->getServiceLocator()->get('Omeka\Connection');

        $bind = [];
        if ($schemeId) {
            $criteria = 'WHERE `scheme_id` = :scheme_id';
            $bind = ['scheme_id' => $schemeId];
        } else {
            $criteria = '';
        }

        $total = (int) $connection
            ->executeQuery("SELECT COUNT(*) FROM `concept` $criteria", $bind)
            ->fetchOne();
        if (!$total) {
            return 0;
        }

        $sqls = [
            <<<SQL
                INSERT INTO `item` (`id`)
                SELECT `id` FROM `concept` $criteria
                ON DUPLICATE KEY UPDATE `id` = `item`.`id`
                SQL,
            <<<SQL
                UPDATE `resource` SET `resource_type` = 'Omeka\\\\Entity\\\\Item'
                WHERE `id` IN (SELECT `id` FROM (SELECT `id` FROM `concept` $criteria) x)
                SQL,
            <<<SQL
                UPDATE `value` SET `type` = 'resource:item'
                WHERE `type` = 'resource:concept'
                    AND `value_resource_id` IN (SELECT `id` FROM (SELECT `id` FROM `concept` $criteria) x)
                SQL,
        ];

        // The table "concept" is kept when only a scheme is converted, so its
        // rows must be removed: the resources are items now.
        if ($schemeId) {
            $sqls[] = <<<SQL
                DELETE FROM `concept` $criteria
                SQL;
        }

        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($sqls as $sql) {
                $connection->executeStatement($sql, $bind);
            }
        } finally {
            $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }

        return $total;
    }

    protected function preInstall(): void
    {
        $services = $this->getServiceLocator();
        $translator = $services->get('MvcTranslator');

        if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.88')) {
            $message = new \Omeka\Stdlib\Message(
                $translator->translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
                'Common', '3.4.88'
            );
            throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
        }
    }

    protected function postInstall(): void
    {
        $this->storeSchemeAndConceptIds();
    }

    public function onBootstrap(MvcEvent $event): void
    {
        parent::onBootstrap($event);
        $this->addAclRoleAndRules();
    }

    /**
     * Add ACL role and rules for this module.
     */
    protected function addAclRoleAndRules(): void
    {
        /**
         * @var \Omeka\Permissions\Acl $acl
         * @see \Omeka\Service\AclFactory
         */
        $services = $this->getServiceLocator();
        $acl = $services->get('Omeka\Acl');

        $roles = $acl->getRoles();

        // Concepts are a resource type with the same access rights as items:
        // public visibility is enforced by the resource visibility filter, and
        // the write rights follow the same role and ownership rules than items.
        $conceptAdapter = \Thesaurus\Api\Adapter\ConceptAdapter::class;
        $conceptEntity = \Thesaurus\Entity\Concept::class;
        $writeOperations = ['create', 'update', 'delete', 'batch_update', 'batch_delete'];
        $ownsAssertion = new \Omeka\Permissions\Assertion\OwnsEntityAssertion();
        $acl
            ->allow(null, [$conceptAdapter], ['search', 'read'])
            ->allow(null, [$conceptEntity], ['read'])
            ->allow('author', [$conceptAdapter], $writeOperations)
            ->allow('author', [$conceptEntity], ['create'])
            ->allow('author', [$conceptEntity], ['update', 'delete'], $ownsAssertion)
            ->allow('reviewer', [$conceptAdapter], $writeOperations)
            ->allow('reviewer', [$conceptEntity], ['create', 'update'])
            ->allow('reviewer', [$conceptEntity], ['delete'], $ownsAssertion)
            ->allow('editor', [$conceptAdapter], $writeOperations)
            ->allow('editor', [$conceptEntity], ['create', 'update', 'delete'])
            ->allow(null, [Controller\Admin\ConceptController::class], ['show', 'show-details'])
            // A concept is created by the thesaurus, so only edition and
            // deletion are available, with the same roles than the items.
            ->allow(['author', 'reviewer', 'editor'], [Controller\Admin\ConceptController::class], ['edit', 'delete', 'delete-confirm']);

        $acl
            ->allow(
                // TODO Except Guest?
                $roles,
                [Controller\Admin\ThesaurusController::class],
                [
                    'index',
                    'browse',
                    'show',
                    'show-details',
                    'sidebar-select',
                    'search',
                    'structure',
                    'jstree',
                ]
            )
            ->allow(
                ['author', 'reviewer'],
                [Controller\Admin\ThesaurusController::class],
                [
                    'index',
                    'browse',
                    'show',
                    'show-details',
                    'sidebar-select',
                    'search',
                    'add',
                    'edit',
                    'delete',
                    'delete-confirm',
                    'structure',
                    'jstree',
                ]
            )
            ->allow(
                ['editor'],
                [Controller\Admin\ThesaurusController::class],
                [
                    'index',
                    'browse',
                    'show',
                    'show-details',
                    'sidebar-select',
                    'search',
                    'add',
                    'edit',
                    'delete',
                    'delete-confirm',
                    'batch-edit',
                    'batch-delete',
                    'structure',
                    'jstree',
                    'reindex',
                ]
            );
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager): void
    {
        // Add the search query filters for resources.
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemAdapter::class,
            'api.search.query',
            [$this, 'handleApiSearchQueryItem']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemSetAdapter::class,
            'api.search.query',
            [$this, 'handleApiSearchQuery']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\MediaAdapter::class,
            'api.search.query',
            [$this, 'handleApiSearchQuery']
        );
        $controllers = [
            'Omeka\Controller\Admin\Item',
            'Omeka\Controller\Admin\ItemSet',
            'Omeka\Controller\Admin\Media',
            'Omeka\Controller\Site\Item',
            'Omeka\Controller\Site\ItemSet',
            'Omeka\Controller\Site\Media',
        ];
        // Optional module DigitalObject: resources are searchable by concept.
        if (class_exists('DigitalObject\Module', false)) {
            $sharedEventManager->attach(
                \DigitalObject\Api\Adapter\DigitalObjectAdapter::class,
                'api.search.query',
                [$this, 'handleApiSearchQuery']
            );
            $controllers[] = 'DigitalObject\Controller\Admin\DigitalObject';
            $controllers[] = 'DigitalObject\Controller\Site\DigitalObject';
        }
        foreach ($controllers as $controller) {
            // foreach ($controllers as $controller) {
            //     // Add the search field to the advanced search pages.
            //     $sharedEventManager->attach(
            //         $controller,
            //         'view.advanced_search',
            //         [$this, 'displayAdvancedSearch']
            //     );
            // }
            // Filter the search filters for the advanced search pages.
            $sharedEventManager->attach(
                $controller,
                'view.search.filters',
                [$this, 'filterSearchFilters']
            );
        }

        // Add css/js to some admin pages.
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Item',
            'view.layout',
            [$this, 'addAdminResourceHeaders']
        );

        // Include ascendance on save.
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemAdapter::class,
            'api.create.pre',
            [$this, 'updateAscendance']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemAdapter::class,
            'api.update.pre',
            [$this, 'updateAscendance']
        );

        // Delete the concepts of a scheme when the scheme itself is deleted.
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemAdapter::class,
            'api.delete.pre',
            [$this, 'deleteSchemeConcepts']
        );

        // Warn in the sidebar of confirmation of the deletion of a scheme. The
        // event "view.details" is used by the sidebar of the browse; the event
        // "view.delete.confirm" is used by the page of edition, but it requires
        // a core that triggers it, so the module works with both.
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Item',
            'view.details',
            [$this, 'warnDeleteScheme']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Item',
            'view.delete.confirm',
            [$this, 'warnDeleteScheme']
        );

        // Warn when the structure of a thesaurus may be outdated.
        $sharedEventManager->attach(
            \Thesaurus\Api\Adapter\ConceptAdapter::class,
            'api.create.post',
            [$this, 'warnOutdatedStructure']
        );
        $sharedEventManager->attach(
            \Thesaurus\Api\Adapter\ConceptAdapter::class,
            'api.update.post',
            [$this, 'warnOutdatedStructure']
        );
        $sharedEventManager->attach(
            \Thesaurus\Api\Adapter\ConceptAdapter::class,
            'api.delete.post',
            [$this, 'warnOutdatedStructure']
        );

        // Register a data type "thesaurus:{schemeId}" by thesaurus scheme.
        $sharedEventManager->attach(
            'Omeka\DataType\Manager',
            'service.registered_names',
            [$this, 'registerThesaurusDataTypes']
        );
        // Make the thesaurus data types available as value annotation.
        $sharedEventManager->attach(
            '*',
            'data_types.value_annotating',
            [$this, 'addThesaurusDataTypesToValueAnnotating']
        );
        // Make the thesaurus data types available in CSV Import.
        $sharedEventManager->attach(
            '*',
            'csv_import.config',
            [$this, 'addThesaurusDataTypesToCsvImport']
        );

        $sharedEventManager->attach(
            \Omeka\Form\SettingForm::class,
            'form.add_elements',
            [$this, 'handleMainSettings']
        );

        // Warn that the concepts are converted back into items on uninstall.
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Module',
            'view.details',
            [$this, 'warnUninstall']
        );

        // Add a job for EasyAdmin.
        $sharedEventManager->attach(
            \EasyAdmin\Form\CheckAndFixForm::class,
            'form.add_elements',
            [$this, 'handleEasyAdminJobsForm']
        );
        $sharedEventManager->attach(
            \EasyAdmin\Controller\Admin\CheckAndFixController::class,
            'easyadmin.job',
            [$this, 'handleEasyAdminJobs']
        );
    }

    /**
     * List the data types "thesaurus:{schemeId}" with their label (the scheme
     * title), one for each thesaurus scheme.
     *
     * @return array<string, string> data type name => scheme title
     */
    protected function getThesaurusDataTypes(): array
    {
        $services = $this->getServiceLocator();
        $schemeClassId = (int) $services->get('Omeka\Settings')->get('thesaurus_skos_scheme_class_id');
        if (!$schemeClassId) {
            return [];
        }

        /** @var \Omeka\Api\Manager $api */
        $api = $services->get('Omeka\ApiManager');
        $schemes = $api->search('items', ['resource_class_id' => $schemeClassId], ['returnScalar' => 'title'])->getContent();

        $result = [];
        foreach ($schemes as $schemeId => $title) {
            $result['thesaurus:' . $schemeId] = (string) $title;
        }
        return $result;
    }

    /**
     * Register a data type "thesaurus:{schemeId}" for each thesaurus scheme.
     */
    public function registerThesaurusDataTypes(Event $event): void
    {
        $dataTypes = $this->getThesaurusDataTypes();
        if (!$dataTypes) {
            return;
        }
        $names = $event->getParam('registered_names');
        $event->setParam('registered_names', array_merge($names, array_keys($dataTypes)));
    }

    /**
     * Add the thesaurus data types to the value annotation.
     */
    public function addThesaurusDataTypesToValueAnnotating(Event $event): void
    {
        $dataTypes = $this->getThesaurusDataTypes();
        if (!$dataTypes) {
            return;
        }
        $valueAnnotating = $event->getParam('data_types');
        $event->setParam('data_types', array_merge($valueAnnotating, array_keys($dataTypes)));
    }

    /**
     * Add the thesaurus data types to the CSV Import config (as resources).
     */
    public function addThesaurusDataTypesToCsvImport(Event $event): void
    {
        $dataTypes = $this->getThesaurusDataTypes();
        if (!$dataTypes) {
            return;
        }
        $config = $event->getParam('config');
        foreach ($dataTypes as $name => $label) {
            $config['data_types'][$name] = [
                'label' => $label,
                'adapter' => 'resource',
            ];
        }
        $event->setParam('config', $config);
    }

    /**
     * Warn that the structure of a thesaurus may be outdated after a save.
     *
     * The tree is rebuilt only by the job IndexThesaurus, because the positions
     * and the top concepts are global to the thesaurus. The scheme, the broader
     * and the top concepts of a single concept are updated on save, but not the
     * positions, neither the removal of a relation.
     *
     * @see \Thesaurus\Api\Adapter\ConceptAdapter::hydrate()
     */
    public function warnOutdatedStructure(Event $event): void
    {
        if ($this->isStructureWarned) {
            return;
        }

        $services = $this->getServiceLocator();
        $isAdminRequest = $services->get('Omeka\Status')->isAdminRequest();

        /** @var \Omeka\Api\Request $request */
        $request = $event->getParam('request');

        // A creation via a job or an import is always followed by a full
        // indexation, so warn only in the admin interface in that case.
        if (!$isAdminRequest && $request->getOperation() === \Omeka\Api\Request::CREATE) {
            return;
        }

        $content = $event->getParam('response')->getContent();

        $scheme = null;
        foreach (is_array($content) ? $content : [$content] as $concept) {
            if ($concept instanceof \Thesaurus\Entity\Concept
                && $this->isOutdatedStructure($concept, $request)
            ) {
                $scheme = $concept->getScheme();
                break;
            }
        }
        if (!$scheme) {
            return;
        }

        // Only one message by request, even for a batch edit.
        $this->isStructureWarned = true;

        if (!$isAdminRequest) {
            $message = new PsrMessage(
                'The structure of the thesaurus "{title}" (#{item_id}) may be outdated: reindex it to rebuild the tree.', // @translate
                ['title' => $scheme->getTitle(), 'item_id' => $scheme->getId()]
            );
            $services->get('Omeka\Logger')->warn($message->getMessage(), $message->getContext());
            return;
        }

        $url = $services->get('ViewHelperManager')->get('url');
        $message = new PsrMessage(
            'The structure of the thesaurus "{title}" (#{item_id}) may be outdated: {link}reindex it{link_end} to rebuild the tree.', // @translate
            [
                'title' => $scheme->getTitle(),
                'item_id' => $scheme->getId(),
                'link' => sprintf('<a href="%s">', htmlspecialchars($url('admin/thesaurus/id', ['action' => 'reindex', 'id' => $scheme->getId()]))),
                'link_end' => '</a>',
            ]
        );
        $message->setEscapeHtml(false);
        $services->get('ControllerPluginManager')->get('messenger')->addWarning($message);
    }

    /**
     * Check if the tree of the thesaurus does not match the skos values.
     */
    protected function isOutdatedStructure(Entity\Concept $concept, \Omeka\Api\Request $request): bool
    {
        // A deletion shifts the positions of all the following concepts and may
        // orphan the narrower ones.
        if ($request->getOperation() === \Omeka\Api\Request::DELETE) {
            return true;
        }

        // A concept that was never indexed has no position.
        if ($concept->getPosition() === null) {
            return true;
        }

        $easyMeta = $this->getServiceLocator()->get('Common\EasyMeta');
        $broader = $this->valueResource($concept, $easyMeta->propertyIds(['skos:broader']));
        $currentBroader = $concept->getBroader();

        if ($broader) {
            return !$currentBroader || $broader->getId() !== $currentBroader->getId();
        }
        if (!$currentBroader) {
            return false;
        }

        // The hierarchy may be stored only as "skos:narrower" in the broader
        // concept, so the missing value "skos:broader" is not an issue when the
        // reciprocal value exists.
        $narrowerIds = $easyMeta->propertyIds(['skos:narrower']);
        foreach ($currentBroader->getValues() as $value) {
            $valueResource = $value->getValueResource();
            if ($valueResource
                && $valueResource->getId() === $concept->getId()
                && in_array($value->getProperty()->getId(), $narrowerIds)
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the first resource used as value for one of the properties.
     */
    protected function valueResource(Entity\Concept $concept, array $propertyIds): ?\Omeka\Entity\Resource
    {
        $propertyIds = array_flip($propertyIds);
        foreach ($concept->getValues() as $value) {
            $valueResource = $value->getValueResource();
            if ($valueResource && isset($propertyIds[$value->getProperty()->getId()])) {
                return $valueResource;
            }
        }
        return null;
    }

    /**
     * Helper to filter search queries for items.
     *
     * @param Event $event
     */
    public function handleApiSearchQueryItem(Event $event): void
    {
        // The sort by thesaurus position is now done on the concepts, that are
        // a dedicated resource type with a "position" field, via their own
        // adapter, so there is no more item hack here.
        $this->handleApiSearchQuery($event);
    }

    /**
     * Helper to filter search queries.
     *
     * @param Event $event
     */
    public function handleApiSearchQuery(Event $event): void
    {
        $query = $event->getParam('request')->getContent();

        $hasQueryProperty = isset($query['property']) && !is_array($query['property']);
        if ($hasQueryProperty) {
            $this->handleApiSearchQueryProperty($event, $query);
        }

        $hasQueryThesaurus = isset($query['thesaurus']) && is_array($query['thesaurus']);
        if ($hasQueryThesaurus) {
            $this->handleApiSearchQueryThesaurus($event, $query);
        }
    }

    protected function handleApiSearchQueryProperty(Event $event, array $query): void
    {
        /**
         * @var \Omeka\Api\Manager $api
         * @var \Thesaurus\Stdlib\Thesaurus $thesaurus
         * @var \Doctrine\ORM\QueryBuilder $qb
         * @var \Omeka\Api\Adapter\ItemAdapter $adapter
         */
        $services = $this->getServiceLocator();
        $api = $services->get('Omeka\ApiManager');
        $thesaurus = $services->get('Thesaurus\Thesaurus');

        $qb = $event->getParam('queryBuilder');
        $adapter = $event->getTarget();

        $expr = $qb->expr();

        $valuesJoin = 'omeka_root.values';
        $where = '';

        foreach ($query['property'] as $queryProperty) {
            if (@$queryProperty['type'] !== 'cat'
                || empty($queryProperty['property'])
                || empty($queryProperty['text'])
                || !is_numeric($queryProperty['text'])
            ) {
                continue;
            }

            // TODO Improve performance: currently, the thesaurus is built manually each time.
            try {
                $item = $api->read('items', ['id' => (int) $queryProperty['text']])->getContent();
            } catch (\Omeka\Api\Exception\NotFoundException $e) {
                continue;
            }

            $thesaurus = $thesaurus($item);
            if (!$thesaurus->isSkos()) {
                continue;
            }
            $list = array_keys($thesaurus->descendantsOrSelf());

            // TODO Fix the issue with the index of the alias of the adapter without "cat_" (when site_id is used in the query too).
            $valuesAlias = $adapter->createAlias('omeka_cat_');
            $predicateExpr = $expr->in(
                $valuesAlias . '.valueResource',
                $adapter->createNamedParameter($qb, $list)
            );

            $joinConditions = [];
            // Narrow to specific property, if one is selected
            if ($queryProperty['property']) {
                if (is_numeric($queryProperty['property'])) {
                    $propertyId = (int) $queryProperty['property'];
                } else {
                    $property = $adapter->getPropertyByTerm($queryProperty['property']);
                    if ($property) {
                        $propertyId = $property->getId();
                    } else {
                        $propertyId = 0;
                    }
                }
                $joinConditions[] = $expr->eq($valuesAlias . '.property', (int) $propertyId);
            }

            $whereClause = '(' . $predicateExpr . ')';

            if ($joinConditions) {
                $qb->leftJoin($valuesJoin, $valuesAlias, Join::WITH, $expr->andX(...$joinConditions));
            } else {
                $qb->leftJoin($valuesJoin, $valuesAlias);
            }

            if ($where == '') {
                $where = $whereClause;
            } elseif ($queryProperty['property'] == 'or') {
                $where .= " OR $whereClause";
            } else {
                $where .= " AND $whereClause";
            }
        }

        if ($where) {
            $qb->andWhere($where);
        }
    }

    /**
     * Handle api query like "thesaurus[dcterms:subject]=xxx.
     *
     * The thesaurus itself and the presence of the value in the thesaurus are
     * not checked.
     */
    protected function handleApiSearchQueryThesaurus(Event $event, array $query): void
    {
        /**
         * @var \Omeka\Api\Manager $api
         * @var \Common\Stdlib\EasyMeta $easyMeta
         * @var \Doctrine\ORM\QueryBuilder $qb
         * @var \Omeka\Api\Adapter\ItemAdapter $adapter
         */
        $services = $this->getServiceLocator();
        $easyMeta = $services->get('Common\EasyMeta');

        $qb = $event->getParam('queryBuilder');
        $adapter = $event->getTarget();

        $expr = $qb->expr();

        $valuesJoin = 'omeka_root.values';

        // The query thesaurus is already checked.
        $queryThesaurus = array_filter($query['thesaurus']);

        foreach ($queryThesaurus as $termOrId => $vals) {
            $propertyId = $easyMeta->propertyId($termOrId);
            if (!$propertyId) {
                continue;
            }
            $valuesAlias = $adapter->createAlias();
            $itemIds = is_array($vals)
                ? array_values(array_filter(array_map('intval', $vals)))
                : array_filter([(int) $vals]);
            if (!$itemIds) {
                // Return no value when error.
                $param = $adapter->createNamedParameter($qb, -1);
                $predicateExpr = $expr->eq("$valuesAlias.valueResource", $param);
            } elseif (count($itemIds) === 1) {
                $param = $adapter->createNamedParameter($qb, reset($itemIds));
                $predicateExpr = $expr->eq("$valuesAlias.valueResource", $param);
            } else {
                $param = $adapter->createNamedParameter($qb, $itemIds);
                $qb->setParameter(substr($param, 1), $itemIds, Connection::PARAM_INT_ARRAY);
                $predicateExpr = $expr->in("$valuesAlias.valueResource", $param);
            }
            $qb
                ->leftJoin($valuesJoin, $valuesAlias)
                ->andWhere($predicateExpr);
        }
    }

    public function filterSearchFilters(Event $event): void
    {
        /**
         * @var \Laminas\View\Renderer\PhpRenderer $view
         * @var \Omeka\Api\Manager $api
         * @var \Common\Stdlib\EasyMeta $easyMeta
         * @var \Thesaurus\Stdlib\Thesaurus $thesaurus
         * @var array $query
         * @var array $filters
         */
        $query = $event->getParam('query', []);
        if (empty($query)) {
            return;
        }

        if (empty($query['thesaurus']) || !is_array($query['thesaurus'])) {
            return;
        }

        $services = $this->getServiceLocator();
        $api = $services->get('Omeka\ApiManager');
        $plugins = $services->get('ControllerPluginManager');
        $translate = $plugins->get('translate');
        $easyMeta = $services->get('Common\EasyMeta');
        // $thesaurus = $services->get('Thesaurus\Thesaurus');

        $is = $translate('is'); // @translate

        foreach ($query['thesaurus'] as $term => $itemIds) {
            if (!$itemIds) {
                continue;
            }
            $propertyLabel = $easyMeta->propertyLabel($term);
            if (!$propertyLabel) {
                continue;
            }
            $filterLabel = $propertyLabel . ' ' . $is;
            $itemTitles = $api->search('items', ['id' => $itemIds], ['returnScalar' => 'title'])->getContent();
            $filters[$filterLabel][] = $itemTitles ? implode(', ', $itemTitles) : '–';
        }

        $event->setParam('filters', $filters);
    }

    public function addAdminResourceHeaders(Event $event): void
    {
        /** @var \Laminas\View\Renderer\PhpRenderer $view */
        $view = $event->getTarget();

        $plugins = $view->getHelperPluginManager();
        $params = $plugins->get('params');
        $action = $params->fromRoute('action');
        if (!in_array($action, ['add', 'edit'])) {
            return;
        }

        if (!$this->isModuleActive('CustomVocab')) {
            return;
        }

        // Get the list of item sets of thesaurus, then the list of custom vocab
        // with items sets, then intersect them.
        // A single query is quicker.

        // Get custom vocab with thesaurus.
        /** @var \Doctrine\DBAL\Connection $connection */
        $connection = $this->getServiceLocator()->get('Omeka\Connection');
        /*
        $sql = <<<'SQL'
SELECT DISTINCT `custom_vocab`.`id`, `custom_vocab`.`item_set_id`
FROM `custom_vocab`
INNER JOIN `item_set` ON `item_set`.`id` = `custom_vocab`.`item_set_id`
WHERE `custom_vocab`.`item_set_id` IS NOT NULL
AND `custom_vocab`.`item_set_id` IN (
    SELECT DISTINCT `item_item_set`.`item_set_id`
    FROM `resource`
    INNER JOIN `thesaurus_term` AS `term` ON `term`.`scheme_id` = `resource`.`id`
    INNER JOIN `item_item_set` ON `item_item_set`.`item_id` = `term`.`scheme_id`
    INNER JOIN `resource_class` ON `resource_class`.`id` = `resource`.`resource_class_id`
    INNER JOIN `vocabulary` ON `vocabulary`.`id` = `resource_class`.`vocabulary_id`
    WHERE `vocabulary`.`prefix` = "skos"
)
;
SQL;
        */

        $subQb = $connection->createQueryBuilder();
        $expr = $subQb->expr();

        $subQb
            ->select('DISTINCT item_item_set.item_set_id')
            ->from('resource', 'resource')
            ->innerJoin('resource', 'concept', 'term', 'term.scheme_id = resource.id')
            ->innerJoin('term', 'item_item_set', 'item_item_set', 'item_item_set.item_id = term.scheme_id')
            ->innerJoin('resource', 'resource_class', 'resource_class', 'resource_class.id = resource.resource_class_id')
            ->innerJoin('resource_class', 'vocabulary', 'vocabulary', 'vocabulary.id = resource_class.vocabulary_id')
            ->where('vocabulary.prefix = "skos"')
        ;

        $qb = $connection->createQueryBuilder();
        $qb
            ->select('DISTINCT custom_vocab.id')
            ->from('custom_vocab', 'custom_vocab')
            ->innerJoin('custom_vocab', 'item_set', 'item_set', 'item_set.id = custom_vocab.item_set_id')
            ->where($expr->isNotNull('custom_vocab.item_set_id'))
            ->andWhere($expr->in('custom_vocab.item_set_id', $subQb->getSQL()))
        ;
        $cvThesaurus = array_map('intval', $connection->executeQuery($qb->getSQL(), $qb->getParameters())->fetchFirstColumn());
        if (!$cvThesaurus) {
            return;
        }

        $script = sprintf('const customVocabThesaurus = %s;', json_encode($cvThesaurus));

        $assetUrl = $plugins->get('assetUrl');
        $plugins->get('headLink')
            ->appendStylesheet($assetUrl('css/thesaurus-admin.css', 'Thesaurus'));
        $plugins->get('headScript')
            ->appendScript($script)
            ->appendFile($assetUrl('js/thesaurus-resource-form.js', 'Thesaurus'), 'text/javascript', ['defer' => 'defer']);
    }

    /**
     * Update ascendance of current item.
     */
    public function updateAscendance(Event $event): void
    {
        /** @var \Omeka\Api\Request $request */
        $request = $event->getParam('request');

        $entityName = $request->getResource();
        if (!$entityName) {
            return;
        }

        $static = $this->getThesaurusSettings();
        if (!$static) {
            return;
        }

        /**
         * @var int $conceptTemplateId
         * @var string|null $propertyDescriptor
         * @var int $propertyDescriptorId
         * @var string|null $propertyPath
         * @var int $propertyPathId
         * @var string|null $propertyAscendance
         * @var int $propertyAscendanceId
         * @var string $separator
         */
        extract($static);

        if (!$propertyPathId && !$propertyAscendanceId) {
            return;
        }

        $content = $request->getContent();

        if (empty($content['o:resource_template']['o:id'])
            || (int) $content['o:resource_template']['o:id'] !== $conceptTemplateId
        ) {
            return;
        }

        // To be managed by the thesaurus, the item should exists already.
        // If not, create the ascendance via the broader resource.
        // In fact, use the broader resource, that exists in all cases!

        $broader = $content['skos:broader'] ?? [];
        if ($broader) {
            $broader = reset($broader);
            if (empty($broader['value_resource_id'])) {
                $ascendanceTitles = [];
            } else {
                /** @var \Thesaurus\Stdlib\Thesaurus $thesaurus */
                $thesaurus = $this->getServiceLocator()->get('Thesaurus\Thesaurus');
                $thesaurus = $thesaurus($broader['value_resource_id']);
                if (!$thesaurus->isSkos() || !$thesaurus->isConcept()) {
                    return;
                }
                $ascendance = $thesaurus->ascendantsOrSelf(true);
                $ascendanceTitles = array_column($ascendance, 'title', 'id');
            }
        } else {
            $ascendanceTitles = [];
        }

        // This is a concept, so update path or ascendance.
        // Just add the ascendance in data, they will be saved automatically.

        if ($propertyPathId) {
            $descriptor = $content[$propertyDescriptor][0]['@value'] ?? '';
            if (mb_strlen($descriptor)) {
                $content[$propertyPath] = [[
                    'type' => 'literal',
                    'property_id' => $propertyPathId,
                    '@value' => (count($ascendanceTitles) ? implode($separator, $ascendanceTitles) . $separator : '')
                        . $descriptor,
                ]];
            } else {
                unset($content[$propertyPath]);
            }
        }

        if ($propertyAscendanceId) {
            if ($ascendanceTitles) {
                $content[$propertyAscendance] = [[
                    'type' => 'literal',
                    'property_id' => $propertyAscendanceId,
                    '@value' => implode($separator, $ascendanceTitles),
                ]];
            } else {
                unset($content[$propertyAscendance]);
            }
        }

        $request->setContent($content);
    }

    public function handleEasyAdminJobsForm(Event $event): void
    {
        /**
         * @var \EasyAdmin\Form\CheckAndFixForm $form
         * @var \Laminas\Form\Element\Radio $process
         */
        $form = $event->getTarget();
        $fieldset = $form->get('module_tasks');
        $process = $fieldset->get('process');
        $valueOptions = $process->getValueOptions();
        $valueOptions['db_thesaurus_index'] = 'Thesaurus: Index thesaurus'; // @translate
        $valueOptions['db_thesaurus_migrate_datatypes'] = 'Thesaurus: Add thesaurus data type to custom vocab templates'; // @translate
        $process->setValueOptions($valueOptions);

        if (method_exists($form, 'addTaskSubjects')) {
            $form->addTaskSubjects([
                'db_thesaurus_index' => [
                    'name' => 'Thesaurus index', // @translate
                    'description' => 'Index the thesaurus terms and their relations.', // @translate
                    'actions' => [
                        'db_thesaurus_index' => 'Index', // @translate
                    ],
                ],
                'db_thesaurus_migrate_datatypes' => [
                    'name' => 'Thesaurus data type migration', // @translate
                    'description' => 'Add the thesaurus data type to the resource templates (and advanced resource template) that use the custom vocab of a thesaurus, without removing the custom vocab.', // @translate
                    'actions' => [
                        'db_thesaurus_migrate_datatypes' => 'Migrate', // @translate
                    ],
                ],
            ]);
        }
    }

    public function handleEasyAdminJobs(Event $event): void
    {
        $process = $event->getParam('process');
        if ($process === 'db_thesaurus_index') {
            $event->setParam('job', \Thesaurus\Job\IndexThesaurus::class);
            $event->setParam('args', []);
        } elseif ($process === 'db_thesaurus_migrate_datatypes') {
            $event->setParam('job', \Thesaurus\Job\MigrateDataTypes::class);
            $event->setParam('args', []);
        }
    }

    protected function getThesaurusSettings(): array
    {
        static $static;

        if ($static !== null) {
            return $static;
        }

        $static = [
            'conceptTemplateId' => 0,
            'propertyDescriptor' => null,
            'propertyDescriptorId' => 0,
            'propertyPath' => null,
            'propertyPathId' => 0,
            'propertyAscendance' => null,
            'propertyAscendanceId' => 0,
            'separator' => null,
        ];

        /**
         * @var \Laminas\ServiceManager\ServiceLocatorInterface $services
         * @var \Omeka\Api\Manager $api
         * @var \Laminas\Log\Logger $logger
         * @var \Omeka\Settings\Settings $settings
         * @see \Thesaurus\Stdlib\Thesaurus $thesaurus
         */
        $services = $this->getServiceLocator();
        $api = $services->get('Omeka\ApiManager');
        $logger = $services->get('Omeka\Logger');
        $settings = $services->get('Omeka\Settings');

        try {
            $conceptTemplateId = (int) $settings->get('thesaurus_skos_concept_template_id');
            $static['conceptTemplateId'] = $conceptTemplateId
                ? $api->read('resource_templates', ['id' => $conceptTemplateId], [], ['responseContent' => 'resource'])->getContent()->getId()
                : $api->read('resource_templates', ['label' => 'Thesaurus Concept'], [], ['responseContent' => 'resource'])->getContent()->getId();
        } catch (\Throwable $e) {
            $logger->err('Unable to find resource template "Thesaurus Concept".'); // @translate
            $static = [];
            return $static;
        }

        try {
            $skosVocabularyId = $api->read('vocabularies', ['prefix' => 'skos'], [], ['responseContent' => 'resource'])->getContent()->getId();
        } catch (\Throwable $e) {
            $logger->err('Unable to find vocabulary Skos.'); // @translate
            $static = [];
            return $static;
        }

        // Descriptor is required.
        $static['propertyDescriptor'] = $settings->get('thesaurus_property_descriptor') ?: 'skos:prefLabel';
        try {
            $static['propertyDescriptorId'] = $api->read('properties', ['vocabulary' => $skosVocabularyId, 'localName' => substr($static['propertyDescriptor'], strpos($static['propertyDescriptor'], ':') + 1)], [], ['responseContent' => 'resource'])->getContent()->getId();
        } catch (\Throwable $e) {
            $logger->err(
                'Unable to find property {term} for descriptor.', // @translate
                ['term' => $static['propertyDescriptor']]
            );
            $static = [];
            return $static;
        }

        $static['propertyPath'] = $settings->get('thesaurus_property_path') ?: null;
        if ($static['propertyPath']) {
            try {
                $static['propertyPathId'] = $api->read('properties', ['vocabulary' => $skosVocabularyId, 'localName' => substr($static['propertyPath'], strpos($static['propertyPath'], ':') + 1)], [], ['responseContent' => 'resource'])->getContent()->getId();
            } catch (\Throwable $e) {
                $logger->err(
                    'Unable to find property {term} for path.', // @translate
                    ['term' => $static['propertyPath']]
                );
                $static = [];
                return $static;
            }
        }

        $static['propertyAscendance'] = $settings->get('thesaurus_property_ascendance') ?: null;
        if ($static['propertyAscendance']) {
            try {
                $static['propertyAscendanceId'] = $api->read('properties', ['vocabulary' => $skosVocabularyId, 'localName' => substr($static['propertyAscendance'], strpos($static['propertyAscendance'], ':') + 1)], [], ['responseContent' => 'resource'])->getContent()->getId();
            } catch (\Throwable $e) {
                $logger->err(
                    'Unable to find property {term} for ascendance.', // @translate
                    ['term' => $static['propertyAscendance']]
                );
                $static = [];
                return $static;
            }
        }

        $static['separator'] = $settings->get('thesaurus_separator', self::SEPARATOR);

        return $static;
    }

    /**
     * @todo Remove or use these settings that store data about thesaurus.
     */
    protected function storeSchemeAndConceptIds(): self
    {
        /**
         * @var \Omeka\Api\Manager $api
         * @var \Omeka\Settings\Settings $settings
         */
        $services = $this->getServiceLocator();
        $api = $services->get('Omeka\ApiManager');
        $settings = $services->get('Omeka\Settings');

        // Update the classes one time at least.
        // Automatically throw exception.
        $vocabulary = $api->read('vocabularies', ['namespaceUri' => 'http://www.w3.org/2004/02/skos/core#'])->getContent();
        $resourceClass = $api->read('resource_classes', ['vocabulary' => $vocabulary->id(), 'localName' => 'ConceptScheme'])->getContent();
        $settings->set('thesaurus_skos_scheme_class_id', $resourceClass->id());
        $resourceClass = $api->read('resource_classes', ['vocabulary' => $vocabulary->id(), 'localName' => 'Concept'])->getContent();
        $settings->set('thesaurus_skos_concept_class_id', $resourceClass->id());

        // Update the template one time at least.
        // Automatically throw exception.
        $template = $api->read('resource_templates', ['label' => 'Thesaurus Scheme'])->getContent();
        $settings->set('thesaurus_skos_scheme_template_id', $template->id());
        $template = $api->read('resource_templates', ['label' => 'Thesaurus Concept'])->getContent();
        $settings->set('thesaurus_skos_concept_template_id', $template->id());

        return $this;
    }
}
