<?php declare(strict_types=1);

namespace Thesaurus\Service\ViewHelper;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Thesaurus\View\Helper\Thesaurus;

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
            $services->get('ControllerPluginManager')->get('thesaurus'),
            $services->get('FormElementManager')
        );
    }
}
