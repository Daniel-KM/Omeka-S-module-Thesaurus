<?php declare(strict_types=1);

namespace Thesaurus\Service\DataType;

use Psr\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\AbstractFactoryInterface;
use Omeka\Api\Exception\NotFoundException;
use Thesaurus\DataType\Thesaurus;

class ThesaurusFactory implements AbstractFactoryInterface
{
    public function canCreate(ContainerInterface $services, $requestedName)
    {
        if (!preg_match('/^thesaurus:(\d+)$/', $requestedName, $matches)) {
            return false;
        }
        try {
            $scheme = $services->get('Omeka\ApiManager')->read('items', $matches[1])->getContent();
        } catch (NotFoundException $e) {
            return false;
        }
        return $services->get('Thesaurus\Thesaurus')->__invoke($scheme)->isScheme();
    }

    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        $id = (int) substr($requestedName, strrpos($requestedName, ':') + 1);
        $scheme = $services->get('Omeka\ApiManager')->read('items', $id)->getContent();
        return new Thesaurus($scheme, $services->get('Thesaurus\Thesaurus'));
    }
}
