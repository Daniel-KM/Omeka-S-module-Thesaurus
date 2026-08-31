<?php declare(strict_types=1);

namespace Thesaurus\Service\Form\Element;

use Psr\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Thesaurus\Form\Element\CustomVocabSelect;

/**
 * @deprecated Since version 3.4.26, use the data type "thesaurus:{schemeId}".
 * @see \Thesaurus\Form\Element\CustomVocabSelect
 */
class CustomVocabSelectFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        // The parent class belongs to the module CustomVocab, that is optional
        // and deprecated for thesaurus.
        if (!class_exists(\CustomVocab\Form\Element\CustomVocabSelect::class)) {
            throw new \Omeka\Service\Exception\RuntimeException(
                'The module CustomVocab is required to use the deprecated custom vocab select.' // @translate
            );
        }

        $select = new CustomVocabSelect(null, $options ?? []);
        $settings = $services->get('Omeka\Settings');
        return $select
            ->setApiManager($services->get('Omeka\ApiManager'))
            ->setThesaurus($services->get('Thesaurus\Thesaurus'))
            ->setDefaultDisplay($settings->get('thesaurus_select_display', 'ascendance'));
    }
}
