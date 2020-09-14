<?php

namespace Thesaurus\Service\Form\Element;

use Interop\Container\ContainerInterface;
use Thesaurus\Form\Element\ThesaurusSelect;
use Zend\ServiceManager\Factory\FactoryInterface;

class ThesaurusSelectFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $element = new ThesaurusSelect(null, $options);
        return $element
            ->setApiManager($services->get('Omeka\ApiManager'))
            ->setThesaurus($services->get('ControllerPluginManager')->get('thesaurus'));
    }
}
