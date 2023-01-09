<?php declare(strict_types=1);

namespace Thesaurus\Service\Form\Element;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Thesaurus\Form\Element\ThesaurusSelect;

class ThesaurusSelectFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $element = new ThesaurusSelect(null, $options ?? []);
        return $element
            ->setThesaurus($services->get('ControllerPluginManager')->get('thesaurus'));
    }
}
