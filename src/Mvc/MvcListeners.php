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
        $this->listeners[] = $events->attach(
            MvcEvent::EVENT_ROUTE,
            [$this, 'handleThesaurus']
        );
    }

    public function handleThesaurus(MvcEvent $event): void
    {
        $routeMatch = $event->getRouteMatch();
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

        $request = $event->getRequest();
        /** @var \Laminas\Stdlib\Parameters $query */
        $query = $request->getQuery();
        $queryArray = $query->toArray();
        $queryArray['resource_class_id'] = [$classId];
        $query->exchangeArray($queryArray);
    }
}
