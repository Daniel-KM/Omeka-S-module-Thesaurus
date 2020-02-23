<?php
namespace Thesaurus;

/*
 * Copyright Daniel Berthereau, 2019-2020
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
use Zend\EventManager\Event;
use Zend\EventManager\SharedEventManagerInterface;

/**
 * Thesaurus
 *
 * Allows to use standard thesaurus (ISO 25964 to describe documents.
 *
 * @copyright Daniel Berthereau, 2018-2019
 * @license http://www.cecill.info/licences/Licence_CeCILL_V2.1-en.txt
 */
class Module extends AbstractModule
{
    const NAMESPACE = __NAMESPACE__;

    protected function postInstall()
    {
        $this->installResources();
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
        // Add the search query filters for resources.
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemAdapter::class,
            'api.search.query',
            [$this, 'handleApiSearchQuery']
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
     * Helper to filter search queries.
     *
     * @param Event $event
     */
    public function handleApiSearchQuery(Event $event)
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

        $isOldOmeka = \Omeka\Module::VERSION < 2;
        $alias = $isOldOmeka ? $adapter->getEntityClass() : 'omeka_root';
        $expr = $qb->expr();

        $valuesJoin = $alias . '.values';
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
            $item = $api->searchOne('items', ['id' => (int) $queryProperty['text']])->getContent();
            if (!$item) {
                continue;
            }

            $thesaurus = $thesaurus($item);
            if (!$thesaurus->isSkos()) {
                continue;
            }
            $list = array_keys($thesaurus->descendantsOrSelf());

            // TODO Fix the issue with the index of the alias of the adapter without "cat_" (when site_id is used in  the query too).
            $valuesAlias = $adapter->createAlias('omeka_cat_');
            $predicateExpr = $expr->in(
                "$valuesAlias.valueResource",
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
                $joinConditions[] = $expr->eq("$valuesAlias.property", (int) $propertyId);
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

    protected function installResources()
    {
        if (!class_exists(\Generic\InstallResources::class)) {
            require_once file_exists(dirname(__DIR__) . '/Generic/InstallResources.php')
                ? dirname(__DIR__) . '/Generic/InstallResources.php'
                : __DIR__ . '/src/Generic/InstallResources.php';
        }

        $services = $this->getServiceLocator();
        $installResources = new \Generic\InstallResources($services);
        $installResources = $installResources();

        // The original files may not be imported fully in Omeka S, so use a
        // simplified but full version of Skos.
        // @url https://lov.linkeddata.es/dataset/lov/vocabs/skos/versions/2009-08-18.n3
        // TODO Remove in Omeka 2.1.
        $vocabulary = [
            'vocabulary' => [
                'o:namespace_uri' => 'http://www.w3.org/2004/02/skos/core#',
                'o:prefix' => 'skos',
                'o:label' => 'Simple Knowledge Organization System', // @translate
                'o:comment' => "An RDF vocabulary for describing the basic structure and content of concept schemes such as thesauri, classification schemes, subject heading lists, taxonomies, 'folksonomies', other types of controlled vocabulary, and also concept schemes embedded in glossaries and terminologies.", // @translate
            ],
            'strategy' => 'file',
            'file' => __DIR__ . '/data/vocabularies/skos_2009-08-18.ttl',
            'format' => 'turtle',
        ];
        $installResources->createVocabulary($vocabulary);

        // Create resource templates.
        $resourceTemplatePaths = [
            __DIR__ . '/data/resource-templates/Thesaurus_Scheme.json',
            __DIR__ . '/data/resource-templates/Thesaurus_Concept.json',
        ];
        foreach ($resourceTemplatePaths as $filepath) {
            $installResources->createResourceTemplate($filepath);
        }
    }
}
