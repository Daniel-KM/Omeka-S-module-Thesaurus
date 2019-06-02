<?php
namespace Thesaurus\Service\ViewHelper;

use Interop\Container\ContainerInterface;
use Thesaurus\View\Helper\Thesaurus;
use Zend\ServiceManager\Factory\FactoryInterface;

/**
 * Service factory for the Thesaurus view helper.
 */
class ThesaurusFactory implements FactoryInterface
{
    /**
     * Create and return the Thesaurus view helper.
     *
     * @return Thesaurus
     */
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new Thesaurus(
            $services->get('ControllerPluginManager')->get('thesaurus')
        );
    }
}
