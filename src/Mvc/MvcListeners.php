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
