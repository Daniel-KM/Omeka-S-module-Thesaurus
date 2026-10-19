<?php declare(strict_types=1);

namespace Thesaurus\Stdlib;

use Doctrine\DBAL\Connection;

/**
 * Manage the data types and the restriction of the templates of a thesaurus.
 *
 * The templates of the thesaurus are reserved to the concepts, that are a
 * dedicated resource type since version 3.4.26. Nevertheless, a thesaurus may
 * be made of items, either because it is not converted yet, either because the
 * concepts are created as items in order to expose them with them. In that
 * case, the data type "resource:item" is required, else the values of these
 * items could not be saved.
 */
class ConceptTemplates
{
    /**
     * Properties that point to a concept, by template label.
     *
     * @var array
     */
    const PROPERTIES = [
        'Thesaurus Concept' => ['skos:broader', 'skos:narrower', 'skos:related', 'skos:semanticRelation', 'skos:closeMatch', 'skos:exactMatch'],
        'Thesaurus Scheme' => ['skos:hasTopConcept'],
    ];

    /**
     * @var \Doctrine\DBAL\Connection
     */
    protected $connection;

    /**
     * @var \Common\Stdlib\EasyMeta
     */
    protected $easyMeta;

    /**
     * @var \Omeka\Settings\Settings
     */
    protected $settings;

    /**
     * @var \Doctrine\ORM\EntityManagerInterface|null
     */
    protected $entityManager;

    public function __construct(Connection $connection, $easyMeta, $settings, $entityManager = null)
    {
        $this->connection = $connection;
        $this->easyMeta = $easyMeta;
        $this->settings = $settings;
        $this->entityManager = $entityManager;
    }

    /**
     * Allow the data type of the items in the templates of the thesaurus.
     *
     * @return int The number of updated template properties.
     */
    public function allowItems(): int
    {
        $total = 0;
        foreach (self::PROPERTIES as $label => $terms) {
            $templateId = $this->templateId($label);
            if (!$templateId) {
                continue;
            }
            $total += $this->appendDataType($templateId, $terms, 'resource:item');
            $this->allowResource($templateId, 'items');
            $this->unreserve($templateId);
        }
        $this->clearTemplateCache();
        return $total;
    }

    /**
     * Add the data type of the concepts and restrict the templates to them.
     *
     * The templates are restricted only when no item uses them.
     *
     * @return int The number of updated template properties.
     */
    public function restrictToConcepts(): int
    {
        $total = 0;
        foreach (self::PROPERTIES as $label => $terms) {
            $templateId = $this->templateId($label);
            if (!$templateId) {
                continue;
            }
            if ($this->isUsedByItems($templateId)) {
                $total += $this->appendDataType($templateId, $terms, 'resource:concept');
                // The template is used by items and by concepts, so it must be
                // available for both.
                $this->allowResource($templateId, 'concepts');
                continue;
            }
            $total += $this->setDataType($templateId, $terms, 'resource:concept');
        }
        return $total;
    }

    /**
     * Clear the cached entities of the templates after a direct update.
     *
     * The data of the templates are updated with sql, so the entities already
     * loaded in the current process would keep the previous restriction and the
     * new resources would be refused.
     */
    protected function clearTemplateCache(): void
    {
        if (!$this->entityManager) {
            return;
        }
        foreach ([
            \Omeka\Entity\ResourceTemplate::class,
            \Omeka\Entity\ResourceTemplateProperty::class,
            'AdvancedResourceTemplate\Entity\ResourceTemplateData',
            'AdvancedResourceTemplate\Entity\ResourceTemplatePropertyData',
        ] as $class) {
            if (class_exists($class)) {
                $this->entityManager->clear($class);
            }
        }
    }

    /**
     * Check if a template is still used by items.
     */
    public function isUsedByItems(int $templateId): bool
    {
        $sql = <<<'SQL'
            SELECT COUNT(*)
            FROM `resource`
            INNER JOIN `item` ON `item`.`id` = `resource`.`id`
            WHERE `resource`.`resource_template_id` = :template_id
            SQL;
        return (bool) (int) $this->connection
            ->executeQuery($sql, ['template_id' => $templateId], ['template_id' => \PDO::PARAM_INT])
            ->fetchOne();
    }

    public function templateId(string $label): ?int
    {
        $id = (int) $this->connection
            ->executeQuery('SELECT `id` FROM `resource_template` WHERE `label` = ?', [$label])
            ->fetchOne();
        return $id ?: null;
    }

    /**
     * Append a data type to the properties of a template.
     *
     * @param string[] $terms
     * @return int The number of updated template properties.
     */
    protected function appendDataType(int $templateId, array $terms, string $dataType): int
    {
        $total = 0;
        foreach ($this->templateProperties($templateId, $terms) as $row) {
            $dataTypes = json_decode((string) $row['data_type'], true) ?: [];
            if (in_array($dataType, $dataTypes)) {
                continue;
            }
            $dataTypes[] = $dataType;
            $this->connection->update('resource_template_property', ['data_type' => json_encode(array_values($dataTypes))], ['id' => $row['id']]);
            ++$total;
        }
        return $total;
    }

    /**
     * Set a single data type to the properties of a template.
     *
     * @param string[] $terms
     * @return int The number of updated template properties.
     */
    protected function setDataType(int $templateId, array $terms, string $dataType): int
    {
        $total = 0;
        foreach ($this->templateProperties($templateId, $terms) as $row) {
            if ((json_decode((string) $row['data_type'], true) ?: []) === [$dataType]) {
                continue;
            }
            $this->connection->update('resource_template_property', ['data_type' => json_encode([$dataType])], ['id' => $row['id']]);
            ++$total;
        }
        return $total;
    }

    /**
     * Get the rows of the properties of a template.
     *
     * @param string[] $terms
     */
    protected function templateProperties(int $templateId, array $terms): array
    {
        $propertyIds = array_values($this->easyMeta->propertyIds($terms));
        if (!$propertyIds) {
            return [];
        }
        return $this->connection
            ->executeQuery(
                'SELECT `id`, `data_type` FROM `resource_template_property` WHERE `resource_template_id` = :template_id AND `property_id` IN (:property_ids)',
                ['template_id' => $templateId, 'property_ids' => $propertyIds],
                ['property_ids' => Connection::PARAM_INT_ARRAY]
            )
            ->fetchAllAssociative();
    }

    /**
     * Make a template available for a type of resource.
     *
     * The restriction is a setting of the module Advanced Resource Template, so
     * it is managed only when its table exists. A template without restriction
     * is available for all the resources, so nothing is done in that case.
     */
    protected function allowResource(int $templateId, string $resourceName): void
    {
        $hasTemplateData = (int) $this->connection
            ->executeQuery("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'resource_template_data'")
            ->fetchOne();
        if (!$hasTemplateData) {
            return;
        }

        $row = $this->connection
            ->executeQuery('SELECT `id`, `data` FROM `resource_template_data` WHERE `resource_template_id` = ?', [$templateId])
            ->fetchAssociative();
        if ($row) {
            $data = json_decode((string) $row['data'], true) ?: [];
            $useForResources = $data['use_for_resources'] ?? [];
            if ($useForResources && !in_array($resourceName, $useForResources)) {
                $data['use_for_resources'] = array_merge($useForResources, [$resourceName]);
                $this->connection->update('resource_template_data', ['data' => json_encode($data)], ['id' => $row['id']]);
            }
        }

        $templatesByResources = $this->settings->get('advancedresourcetemplate_templates_by_resource');
        if (is_array($templatesByResources)
            && array_key_exists($resourceName, $templatesByResources)
            && !in_array($templateId, (array) $templatesByResources[$resourceName])
        ) {
            $templatesByResources[$resourceName] = array_merge((array) $templatesByResources[$resourceName], [$templateId]);
            $this->settings->set('advancedresourcetemplate_templates_by_resource', $templatesByResources);
        }

        $this->clearTemplateCache();
    }

    /**
     * Remove the restriction of a template to the concepts.
     *
     * The restriction is a setting of the module Advanced Resource Template, so
     * it is managed only when its table exists. The template remains limited to
     * the items and the concepts: it has no meaning for the other resources.
     */
    protected function unreserve(int $templateId): void
    {
        $hasTemplateData = (int) $this->connection
            ->executeQuery("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'resource_template_data'")
            ->fetchOne();
        if (!$hasTemplateData) {
            return;
        }

        $row = $this->connection
            ->executeQuery('SELECT `id`, `data` FROM `resource_template_data` WHERE `resource_template_id` = ?', [$templateId])
            ->fetchAssociative();
        if (!$row) {
            return;
        }

        $data = json_decode((string) $row['data'], true) ?: [];
        if (($data['use_for_resources'] ?? null) !== ['concepts']) {
            return;
        }

        $data['use_for_resources'] = ['items', 'concepts'];
        $this->connection->update('resource_template_data', ['data' => json_encode($data)], ['id' => $row['id']]);
        $this->clearTemplateCache();

        $this->allowResource($templateId, 'items');
    }
}
