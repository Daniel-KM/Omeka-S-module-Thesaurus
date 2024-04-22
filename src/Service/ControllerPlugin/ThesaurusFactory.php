<?php declare(strict_types=1);

namespace Thesaurus\Service\ControllerPlugin;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Thesaurus\Mvc\Controller\Plugin\Thesaurus;

class ThesaurusFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $name, array $options = null)
    {
        return new Thesaurus(
            $services->get('Thesaurus\Thesaurus')
        );
    }
}
