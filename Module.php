<?php declare(strict_types=1);

namespace Thesaurus;

/*
 * Copyright Daniel Berthereau, 2018-2023
 *
 * This software is governed by the CeCILL license under French law and abiding
 * by the rules of distribution of free software.  You can use, modify and/ or
 * redistribute the software under the terms of the CeCILL license as circulated
 * by CEA, CNRS and INRIA at the following URL "http://www.cecill.info".
 *
 * As a counterpart to the access to the source code and rights to copy, modify
 * and redistribute granted by the license, users are provided only with a
 * limited warranty and the software's author, the holder of the economic
 * rights, and the successive licensors have only limited liability.
 *
 * In this respect, the user's attention is drawn to the risks associated with
 * loading, using, modifying and/or developing or reproducing the software by
 * the user in light of its specific status of free software, that may mean that
 * it is complicated to manipulate, and that also therefore means that it is
 * reserved for developers and experienced professionals having in-depth
 * computer knowledge. Users are therefore encouraged to load and test the
 * software's suitability as regards their requirements in conditions enabling
 * the security of their systems and/or data to be ensured and, more generally,
 * to use and operate it in the same conditions as regards security.
 *
 * The fact that you are presently reading this means that you have had
 * knowledge of the CeCILL license and that you accept its terms.
 */

if (!class_exists(\Generic\AbstractModule::class)) {
    require file_exists(dirname(__DIR__) . '/Generic/AbstractModule.php')
        ? dirname(__DIR__) . '/Generic/AbstractModule.php'
        : __DIR__ . '/src/Generic/AbstractModule.php';
}

use Doctrine\ORM\Query\Expr\Join;
use Generic\AbstractModule;
use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Mvc\MvcEvent;

/**
 * Thesaurus
 *
 * Allows to use standard thesaurus (ISO 25964 to describe documents).
 *
 * @copyright Daniel Berthereau, 2018-2023
 * @license http://www.cecill.info/licences/Licence_CeCILL_V2.1-en.txt
 */
class Module extends AbstractModule
{
    const NAMESPACE = __NAMESPACE__;

    const SEPARATOR = ' :: ';

    protected function postInstall(): void
    {
        $this->storeSchemeAndConceptIds();

        if ($this->isModuleActive('CustomVocab')) {
            return;
        }

        $services = $this->getServiceLocator();
        $messenger = $services->get('ControllerPluginManager')->get('messenger');

        $message = new \Omeka\Stdlib\Message(
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

        // See module Next: an issue may occur when there are multiple properties.
        // Anyway, it's a selection of categories, so other properties can be set
        // separately, but not in the form.
        // $controllers = [
        //     'Omeka\Controller\Admin\Item',
        //     'Omeka\Controller\Admin\ItemSet',
        //     'Omeka\Controller\Admin\Media',
        //     'Omeka\Controller\Site\Item',
        //     'Omeka\Controller\Site\ItemSet',
        //     'Omeka\Controller\Site\Media',
        // ];
        // foreach ($controllers as $controller) {
        //     // Add the search field to the advanced search pages.
        //     $sharedEventManager->attach(
        //         $controller,
        //         'view.advanced_search',
        //         [$this, 'displayAdvancedSearch']
        //     );
        //     // Filter the search filters for the advanced search pages.
        //     $sharedEventManager->attach(
        //         $controller,
        //         'view.search.filters',
        //         [$this, 'filterSearchFilters']
        //     );
        // }
    }

    /**
     * Helper to filter search queries for items.
     *
     * @param Event $event
     */
    public function handleApiSearchQueryItem(Event $event)
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

        return $this->handleApiSearchQuery($event);
    }

    /**
     * Helper to filter search queries.
     *
     * @param Event $event
     */
    public function handleApiSearchQuery(Event $event): void
    {
        $query = $event->getParam('request')->getContent();
        if (!isset($query['property']) || !is_array($query['property'])) {
            return;
        }

        $plugins = $this->getServiceLocator()->get('ControllerPluginManager');
        /**
         * @var \Omeka\Mvc\Controller\Plugin\Api $api
         * @var \Thesaurus\Mvc\Controller\Plugin\Thesaurus $thesaurus
         */
        $api = $plugins->get('api');
        $thesaurus = $plugins->get('thesaurus');

        /**
         * @var \Doctrine\ORM\QueryBuilder $qb
         * @var \Omeka\Api\Adapter\ItemAdapter $adapter
         */
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
            $item = $api->searchOne('items', ['id' => (int) $queryProperty['text']], ['initialize' => false])->getContent();
            if (!$item) {
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
                /** @var \Thesaurus\Mvc\Controller\Plugin\Thesaurus $thesaurus */
                $thesaurus = $this->getServiceLocator()->get('ControllerPluginManager')->get('thesaurus');
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
         * @var \Thesaurus\Mvc\Controller\Plugin\Thesaurus $thesaurus
         */
        $services = $this->getServiceLocator();
        $api = $services->get('Omeka\ApiManager');
        $logger = $services->get('Omeka\Logger');
        $settings = $services->get('Omeka\Settings');

        try {
            $static['conceptTemplateId'] = $api->read('resource_templates', ['label' => 'Thesaurus Concept'], [], ['responseContent' => 'resource'])->getContent()->getId();
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
            $logger->err('Unable to find property "%s" for descriptor.', $static['propertyDescriptor']); // @translate
            $static = [];
            return $static;
        }

        $static['propertyPath'] = $settings->get('thesaurus_property_path') ?: null;
        if ($static['propertyPath']) {
            try {
                $static['propertyPathId'] = $api->read('properties', ['vocabulary' => $skosVocabularyId, 'localName' => substr($static['propertyPath'], strpos($static['propertyPath'], ':') + 1)], [], ['responseContent' => 'resource'])->getContent()->getId();
            } catch (\Exception $e) {
                $logger->err('Unable to find property "%s" for path.', $static['propertyPath']); // @translate
                $static = [];
                return $static;
            }
        }

        $static['propertyAscendance'] = $settings->get('thesaurus_property_ascendance') ?: null;
        if ($static['propertyAscendance']) {
            try {
                $static['propertyAscendanceId'] = $api->read('properties', ['vocabulary' => $skosVocabularyId, 'localName' => substr($static['propertyAscendance'], strpos($static['propertyAscendance'], ':') + 1)], [], ['responseContent' => 'resource'])->getContent()->getId();
            } catch (\Exception $e) {
                $logger->err('Unable to find property "%s" for ascendance.', $static['propertyAscendance']); // @translate
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
