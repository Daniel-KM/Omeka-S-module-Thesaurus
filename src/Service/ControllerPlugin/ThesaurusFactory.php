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
        $settings = $services->get('Omeka\Settings');
        return new Thesaurus(
            $services->get('Omeka\ApiManager'),
            $services->get('Omeka\EntityManager'),
            $services->get('Omeka\ApiAdapterManager')->get('items'),
            $services->get('Omeka\Logger'),
            $settings,
            $plugins->get('identity')(),
            $settings->get('thesaurus_separator', \Thesaurus\Module::SEPARATOR)
        );
    }
}
