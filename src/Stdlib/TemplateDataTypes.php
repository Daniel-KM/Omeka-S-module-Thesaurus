<?php declare(strict_types=1);

namespace Thesaurus\Stdlib;

use Doctrine\DBAL\Connection;

/**
 * Allow in resource templates the thesaurus data types used by their values.
 *
 * A value filled with a concept via a custom vocab uses the data type of the
 * thesaurus after the conversion of the concepts, so a template property that
 * restricts the data types should allow it too, else the resource is refused on
 * save. A concept may be selected via another custom vocab than the one of its
 * thesaurus, so the data types are determined from the values.
 */
class TemplateDataTypes
{
    /**
     * @var \Doctrine\DBAL\Connection
     */
    protected $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Append the missing thesaurus data types to the template properties.
     *
     * A template property without data type allows all of them, so it is kept.
     *
     * @return int The number of updated template properties and advanced
     * template property configs.
     */
    public function allowUsedThesaurusTypes(?array $schemeIds = null): int
    {
        return $this->process($schemeIds, false);
    }

    /**
     * @param int[]|null $schemeIds Limit to the data types of these thesaurus.
     * @return int The number of template properties and configs to update.
     */
    public function countMissingThesaurusTypes(?array $schemeIds = null): int
    {
        return $this->process($schemeIds, true);
    }

    /**
     * Get the thesaurus whose data type is missing in a template property.
     *
     * @return int[]
     */
    public function schemeIdsWithMissingTypes(?array $schemeIds = null): array
    {
        $missing = [];
        $this->process($schemeIds, true, $missing);
        return $missing;
    }

    /**
     * Append the missing data types, or only count the rows to update.
     *
     * @param int[]|null $missingSchemeIds Filled with the thesaurus to fix.
     */
    protected function process(?array $schemeIds, bool $countOnly, ?array &$missingSchemeIds = null): int
    {
        $types = $schemeIds
            ? array_map(fn ($schemeId) => 'thesaurus:' . (int) $schemeId, $schemeIds)
            : [];
        $sql = <<<'SQL'
            SELECT DISTINCT `rtp`.`id`, `value`.`type`
            FROM `value`
            INNER JOIN `resource` ON `resource`.`id` = `value`.`resource_id`
            INNER JOIN `resource_template_property` AS `rtp`
                ON `rtp`.`resource_template_id` = `resource`.`resource_template_id`
                AND `rtp`.`property_id` = `value`.`property_id`
            WHERE `value`.`type` LIKE 'thesaurus:%'
            SQL;
        $params = [];
        $paramTypes = [];
        if ($types) {
            $sql .= "\n    AND `value`.`type` IN (:types)";
            $params = ['types' => $types];
            $paramTypes = ['types' => Connection::PARAM_STR_ARRAY];
        }

        $usedTypes = [];
        foreach ($this->connection->executeQuery($sql, $params, $paramTypes)->iterateNumeric() as [$rtpId, $type]) {
            $usedTypes[(int) $rtpId][] = $type;
        }
        if (!$usedTypes) {
            return 0;
        }

        $count = 0;

        $rows = $this->connection
            ->executeQuery(
                'SELECT `id`, `data_type` FROM `resource_template_property` WHERE `id` IN (:ids)',
                ['ids' => array_keys($usedTypes)],
                ['ids' => Connection::PARAM_INT_ARRAY]
            )
            ->fetchAllKeyValue();
        foreach ($rows as $id => $dataType) {
            $list = json_decode((string) $dataType, true);
            $new = $this->appendTypes($list, $usedTypes[(int) $id]);
            if ($new) {
                $this->collectSchemeIds($missingSchemeIds, $usedTypes[(int) $id]);
                if (!$countOnly) {
                    $this->connection->executeStatement(
                        'UPDATE `resource_template_property` SET `data_type` = :data_type WHERE `id` = :id',
                        ['data_type' => json_encode($new), 'id' => (int) $id]
                    );
                }
                ++$count;
            }
        }

        // The module Advanced Resource Template may restrict the data types of
        // each config of a template property.
        if (!$this->connection->executeQuery("SHOW TABLES LIKE 'resource_template_property_data'")->fetchOne()) {
            return $count;
        }
        $rows = $this->connection
            ->executeQuery(
                'SELECT `id`, `resource_template_property_id`, `data` FROM `resource_template_property_data` WHERE `resource_template_property_id` IN (:ids)',
                ['ids' => array_keys($usedTypes)],
                ['ids' => Connection::PARAM_INT_ARRAY]
            )
            ->fetchAllAssociative();
        foreach ($rows as $row) {
            $data = json_decode((string) $row['data'], true);
            if (!is_array($data)) {
                continue;
            }
            $new = $this->appendTypes($data['o:data_type'] ?? null, $usedTypes[(int) $row['resource_template_property_id']]);
            if ($new) {
                $this->collectSchemeIds($missingSchemeIds, $usedTypes[(int) $row['resource_template_property_id']]);
                if (!$countOnly) {
                    $data['o:data_type'] = $new;
                    $this->connection->executeStatement(
                        'UPDATE `resource_template_property_data` SET `data` = :data WHERE `id` = :id',
                        ['data' => json_encode($data), 'id' => (int) $row['id']]
                    );
                }
                ++$count;
            }
        }

        return $count;
    }

    /**
     * Append the ids of the thesaurus of a list of data types to a list.
     *
     * @param string[] $types
     */
    protected function collectSchemeIds(?array &$schemeIds, array $types): void
    {
        if ($schemeIds === null) {
            return;
        }
        foreach ($types as $type) {
            $schemeId = (int) mb_substr($type, mb_strlen('thesaurus:'));
            if ($schemeId && !in_array($schemeId, $schemeIds)) {
                $schemeIds[] = $schemeId;
            }
        }
    }

    /**
     * Get the list of data types with the missing ones, or null if unchanged.
     */
    protected function appendTypes($list, array $types): ?array
    {
        if (!is_array($list) || !$list) {
            return null;
        }
        $missing = array_diff($types, $list);
        return $missing
            ? array_merge(array_values($list), array_values($missing))
            : null;
    }
}
