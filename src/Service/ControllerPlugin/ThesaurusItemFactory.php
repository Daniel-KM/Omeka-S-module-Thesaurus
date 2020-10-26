<?php
namespace Thesaurus\Service\ControllerPlugin;

use Interop\Container\ContainerInterface;
use Thesaurus\Mvc\Controller\Plugin\ThesaurusItem;
use Laminas\ServiceManager\Factory\FactoryInterface;

class ThesaurusItemFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $name, array $options = null)
    {
        return new ThesaurusItem(
            $services->get('Omeka\EntityManager'),
            $services->get('ControllerPluginManager')->get('api')
        );
    }
}
