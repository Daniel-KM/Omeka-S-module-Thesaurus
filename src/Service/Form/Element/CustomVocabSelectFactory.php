<?php declare(strict_types=1);

namespace Thesaurus\Service\Form\Element;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Thesaurus\Form\Element\CustomVocabSelect;

class CustomVocabSelectFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $select = new CustomVocabSelect(null, $options ?? []);
        $settings = $services->get('Omeka\Settings');
        return $select
            ->setApiManager($services->get('Omeka\ApiManager'))
            ->setThesaurus($services->get('Thesaurus\Thesaurus'))
            ->setDefaultDisplay($settings->get('thesaurus_select_display', 'ascendance'));
    }
}
