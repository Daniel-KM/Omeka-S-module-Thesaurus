<?php
namespace Thesaurus\Service\ControllerPlugin;

use Interop\Container\ContainerInterface;
use Thesaurus\Mvc\Controller\Plugin\Thesaurus;
use Zend\ServiceManager\Factory\FactoryInterface;

class ThesaurusFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $name, array $options = null)
    {
        return new Thesaurus(
            $services->get('Omeka\EntityManager'),
            $services->get('Omeka\ApiAdapterManager')->get('items'),
            $services->get('ControllerPluginManager')->get('api')
        );
    }
}
