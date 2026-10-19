<?php declare(strict_types=1);

namespace Thesaurus\Mvc;

use Laminas\EventManager\AbstractListenerAggregate;
use Laminas\EventManager\EventManagerInterface;
use Laminas\Mvc\MvcEvent;

class MvcListeners extends AbstractListenerAggregate
{
    /**
     * @var MvcEvent
     */
    protected $event;

    public function attach(EventManagerInterface $events, $priority = 1): void
    {
        // Low priority: the route match is set by the core RouteListener at
        // priority 1, so it must be read after it, else it may be null.
        $this->listeners[] = $events->attach(
            MvcEvent::EVENT_ROUTE,
            [$this, 'handleThesaurus'],
            -100
        );
        $this->listeners[] = $events->attach(
            MvcEvent::EVENT_ROUTE,
            [$this, 'redirectItemToConcept'],
            -100
        );
    }

    /**
     * Redirect the page of an item converted into a concept to the concept.
     *
     * The id is kept by the conversion, so the old links remain valid.
     */
    public function redirectItemToConcept(MvcEvent $event)
    {
        $routeMatch = $event->getRouteMatch();
        if (!$routeMatch) {
            return null;
        }

        $routes = [
            'site/resource-id' => 'site/concept',
            'admin/id' => 'admin/concept/id',
        ];
        $matchedRouteName = $routeMatch->getMatchedRouteName();
        if (!isset($routes[$matchedRouteName])
            || $routeMatch->getParam('action', 'show') !== 'show'
            || strtolower((string) $routeMatch->getParam('__CONTROLLER__', $routeMatch->getParam('controller'))) !== 'item'
        ) {
            return null;
        }

        $id = (int) $routeMatch->getParam('id');
        $resourceType = $event->getApplication()->getServiceManager()->get('Omeka\Connection')
            ->executeQuery('SELECT `resource_type` FROM `resource` WHERE `id` = ?', [$id])
            ->fetchOne();
        if ($resourceType !== \Thesaurus\Entity\Concept::class) {
            return null;
        }

        $url = $event->getRouter()->assemble(
            ['site-slug' => $routeMatch->getParam('site-slug'), 'id' => $id],
            ['name' => $routes[$matchedRouteName]]
        );

        /** @var \Laminas\Http\PhpEnvironment\Response $response */
        $response = $event->getResponse();
        $response->getHeaders()->addHeaderLine('Location', $url);
        $response->setStatusCode(301);
        return $response;
    }

    public function handleThesaurus(MvcEvent $event): void
    {
        $routeMatch = $event->getRouteMatch();
        if (!$routeMatch) {
            return;
        }

        $matchedRouteName = $routeMatch->getMatchedRouteName();
        if (!in_array($matchedRouteName, ['admin/thesaurus', 'admin/thesaurus/default'])) {
            return;
        }

        $action = $routeMatch->getParam('action', 'browse');
        if ($action !== 'browse') {
            return;
        }

        /** @var \Omeka\Settings\Settings $settings */
        $settings = $event->getApplication()->getServiceManager()->get('Omeka\Settings');
        $classId = (int) $settings->get('thesaurus_skos_scheme_class_id');
        // Without a scheme class, don't filter by resource_class_id = [0],
        // which would return an empty list.
        if (!$classId) {
            return;
        }

        $request = $event->getRequest();
        /** @var \Laminas\Stdlib\Parameters $query */
        $query = $request->getQuery();
        $queryArray = $query->toArray();
        $queryArray['resource_class_id'] = [$classId];
        $query->exchangeArray($queryArray);
    }
}
