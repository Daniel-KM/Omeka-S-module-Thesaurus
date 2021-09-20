<?php declare(strict_types=1);

namespace Thesaurus\Service\ControllerPlugin;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Thesaurus\Mvc\Controller\Plugin\ThesaurusItem;

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
