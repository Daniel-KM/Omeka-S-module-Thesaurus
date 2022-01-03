<?php declare(strict_types=1);

namespace Thesaurus\Service\Controller\Admin;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Thesaurus\Controller\Admin\ThesaurusController;

class ThesaurusControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        // Required only because the controller extends Omeka ItemController for now.
        return new ThesaurusController(
            $services->get('Omeka\Media\Ingester\Manager')
        );
    }
}
