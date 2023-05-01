<?php declare(strict_types=1);

namespace Thesaurus\Service\ControllerPlugin;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Thesaurus\Mvc\Controller\Plugin\Thesaurus;

class ThesaurusFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $name, array $options = null)
    {
        $plugins = $services->get('ControllerPluginManager');
        return new Thesaurus(
            $services->get('Omeka\EntityManager'),
            $services->get('Omeka\ApiAdapterManager')->get('items'),
            $services->get('Omeka\ApiManager'),
            $services->get('Omeka\Logger'),
            $plugins->get('identity'),
            $services->get('Omeka\Settings')->get('thesaurus_separator', \Thesaurus\Module::SEPARATOR)
        );
    }
}
