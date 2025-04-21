<?php declare(strict_types=1);

namespace Thesaurus;

if (!class_exists(\Common\TraitModule::class)) {
    require_once dirname(__DIR__) . '/Common/TraitModule.php';
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
 * @copyright Daniel Berthereau, 2018-2025
 * @license http://www.cecill.info/licences/Licence_CeCILL_V2.1-en.txt
 */
class Module extends AbstractModule
{
    use TraitModule;

    const NAMESPACE = __NAMESPACE__;

    const SEPARATOR = ' :: ';

    protected function preInstall(): void
    {
        $services = $this->getServiceLocator();
        $translate = $services->get('ControllerPluginManager')->get('translate');

        if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.66')) {
            $message = new \Omeka\Stdlib\Message(
                $translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
                'Common', '3.4.66'
            );
            throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
        }
    }

    protected function postInstall(): void
    {
        $this->storeSchemeAndConceptIds();

        if ($this->isModuleActive('CustomVocab')) {
            return;
        }

        $services = $this->getServiceLocator();
        $messenger = $services->get('ControllerPluginManager')->get('messenger');

        $message = new PsrMessage(
            'It is recommended to install module CustomVocab to take full advantage of this module.' // @translate
        );
        $messenger->addWarning($message);
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

        // Access rights like items.
        // Rights for controllers only, since schemes and concepts are items.

        $roles = $acl->getRoles();

        $acl
            ->allow(
                // TODO Except Guest?
                $roles,
                [Controller\Admin\ThesaurusController::class],
                [
                    'index', 'browse', 'show', 'show-details', 'sidebar-select', 'search',
                    'structure', 'jstree',
                ]
            )
            ->allow(
                ['author', 'reviewer'],
                [Controller\Admin\ThesaurusController::class],
                [
                    'index', 'browse', 'show', 'show-details', 'sidebar-select', 'search',
                    'add', 'edit', 'delete', 'delete-confirm',
                    'structure', 'jstree',
                ]
            )
            ->allow(
                ['editor'],
                [Controller\Admin\ThesaurusController::class],
                [
                    'index', 'browse', 'show', 'show-details', 'sidebar-select', 'search',
                    'add', 'edit', 'delete', 'delete-confirm', 'batch-edit', 'batch-delete',
                    'structure', 'jstree', 'reindex',
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

        $sharedEventManager->attach(
            \Omeka\Form\SettingForm::class,
            'form.add_elements',
            [$this, 'handleMainSettings']
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
     * Helper to filter search queries for items.
     *
     * @param Event $event
     */
    public function handleApiSearchQueryItem(Event $event): void
    {
        $query = $event->getParam('request')->getContent();
        if (!empty($query['sort_thesaurus'])
            && is_numeric($query['sort_thesaurus'])
            && (int) $query['sort_thesaurus']
            && isset($query['sort_by']) && $query['sort_by'] === 'thesaurus'
        ) {
            /**
             * @var \Omeka\Api\Adapter\ItemAdapter $adapter
             * @var \Doctrine\ORM\QueryBuilder $qb
             */
            $adapter = $event->getTarget();
            $qb = $event->getParam('queryBuilder');

            $expr = $qb->expr();

            $termAlias = $adapter->createAlias();
            $qb
                ->addSelect($termAlias . '.position HIDDEN')
                ->leftJoin(
                    \Thesaurus\Entity\Term::class,
                    $termAlias,
                    \Doctrine\ORM\Query\Expr\Join::WITH,
                    $expr->andX(
                        $expr->eq($termAlias . '.item', 'omeka_root'),
                        $expr->eq($termAlias . '.scheme', (int) $query['sort_thesaurus'])
                    )
                )
                ->addOrderBy(
                    $termAlias . '.position',
                    isset($query['sort_order']) && strtolower((string) $query['sort_order'] === 'DESC') ? 'DESC' : 'ASC'
                )
            ;
        }

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
    INNER JOIN `term` ON `term`.`scheme_id` = `resource`.`id`
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
            ->innerJoin('resource', 'term', 'term', 'term.scheme_id = resource.id')
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
        $cvThesaurus = array_map('intval', $connection->executeQuery($qb)->fetchFirstColumn());
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
         * @var ?string $propertyDescriptor
         * @var int $propertyDescriptorId
         * @var ?string $propertyPath
         * @var int $propertyPathId
         * @var ?string $propertyAscendance
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
        $process->setValueOptions($valueOptions);
    }

    public function handleEasyAdminJobs(Event $event): void
    {
        $process = $event->getParam('process');
        if ($process === 'db_thesaurus_index') {
            $event->setParam('job', \Thesaurus\Job\IndexThesaurus::class);
            $event->setParam('args', []);
        }
    }

    protected function getThesaurusSettings(): array
    {
        static $static;

        if (!is_null($static)) {
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
        } catch (\Exception $e) {
            $logger->err('Unable to find resource template "Thesaurus Concept".'); // @translate
            $static = [];
            return $static;
        }

        try {
            $skosVocabularyId = $api->read('vocabularies', ['prefix' => 'skos'], [], ['responseContent' => 'resource'])->getContent()->getId();
        } catch (\Exception $e) {
            $logger->err('Unable to find vocabulary Skos.'); // @translate
            $static = [];
            return $static;
        }

        // Descriptor is required.
        $static['propertyDescriptor'] = $settings->get('thesaurus_property_descriptor') ?: 'skos:prefLabel';
        try {
            $static['propertyDescriptorId'] = $api->read('properties', ['vocabulary' => $skosVocabularyId, 'localName' => substr($static['propertyDescriptor'], strpos($static['propertyDescriptor'], ':') + 1)], [], ['responseContent' => 'resource'])->getContent()->getId();
        } catch (\Exception $e) {
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
            } catch (\Exception $e) {
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
            } catch (\Exception $e) {
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
