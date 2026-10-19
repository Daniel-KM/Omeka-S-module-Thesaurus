<?php declare(strict_types=1);

namespace Thesaurus\Service\DataType;

use Laminas\ServiceManager\Factory\AbstractFactoryInterface;
use Psr\Container\ContainerInterface;
use Thesaurus\DataType\Thesaurus;

class ThesaurusFactory implements AbstractFactoryInterface
{
    public function canCreate(ContainerInterface $services, $requestedName)
    {
        if (!preg_match('/^thesaurus:(\d+)$/', $requestedName, $matches)) {
            return false;
        }
        return $this->isScheme($services, (int) $matches[1]);
    }

    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        $id = (int) substr($requestedName, strrpos($requestedName, ':') + 1);
        return new Thesaurus(
            $id,
            $services->get('Omeka\ApiManager'),
            $services->get('Thesaurus\Thesaurus')
        );
    }

    /**
     * Check that an item is a thesaurus scheme, without reading it.
     *
     * The values of a scheme may use the data type of its own thesaurus, for
     * example when a concept is a subject of the scheme, so reading the item
     * here would require this data type again, recursively.
     */
    protected function isScheme(ContainerInterface $services, int $id): bool
    {
        if (!$id) {
            return false;
        }

        /** @var \Doctrine\DBAL\Connection $connection */
        $connection = $services->get('Omeka\Connection');

        $sql = <<<'SQL'
            SELECT COUNT(*)
            FROM `resource`
            LEFT JOIN `resource_class` ON `resource_class`.`id` = `resource`.`resource_class_id`
            LEFT JOIN `vocabulary` ON `vocabulary`.`id` = `resource_class`.`vocabulary_id`
            WHERE `resource`.`id` = :id
                AND `resource`.`resource_type` = 'Omeka\\Entity\\Item'
                AND (
                    (`vocabulary`.`prefix` = 'skos' AND `resource_class`.`local_name` = 'ConceptScheme')
                    OR EXISTS (
                        SELECT 1 FROM `concept` WHERE `concept`.`scheme_id` = :id
                    )
                    OR EXISTS (
                        SELECT 1
                        FROM `value`
                        INNER JOIN `property` ON `property`.`id` = `value`.`property_id`
                        INNER JOIN `vocabulary` AS `vocabulary_property` ON `vocabulary_property`.`id` = `property`.`vocabulary_id`
                        WHERE `value`.`resource_id` = :id
                            AND `vocabulary_property`.`prefix` = 'skos'
                            AND `property`.`local_name` = 'hasTopConcept'
                    )
                )
            SQL;

        return (bool) $connection->executeQuery($sql, ['id' => $id])->fetchOne();
    }
}
