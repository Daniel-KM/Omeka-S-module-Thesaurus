<?php declare(strict_types=1);

namespace Thesaurus\Job;

use Doctrine\DBAL\Connection;
use Omeka\Job\AbstractJob;

/**
 * Add the data type "thesaurus:{schemeId}" to the configurations that use the
 * custom vocab of a thesaurus (resource templates, advanced resource template),
 * without removing the custom vocab. Settings are only reported for a manual
 * review, since the meaning of a data type in a setting is module specific.
 */
class MigrateDataTypes extends AbstractJob
{
    public function perform(): void
    {
        $services = $this->getServiceLocator();
        $logger = $services->get('Omeka\Logger');
        /** @var \Doctrine\DBAL\Connection $connection */
        $connection = $services->get('Omeka\Connection');
        $settings = $services->get('Omeka\Settings');

        $schemeClassId = (int) $settings->get('thesaurus_skos_scheme_class_id');
        if (!$schemeClassId) {
            $logger->warn('The thesaurus scheme class is not set; the module may not be fully installed.'); // @translate
            return;
        }

        $cvToScheme = $this->mapCustomVocabToScheme($connection, $schemeClassId);
        if (!$cvToScheme) {
            $logger->notice('No thesaurus custom vocab found; nothing to migrate.'); // @translate
            return;
        }

        $countRtp = $this->migrateResourceTemplateProperties($connection, $cvToScheme);
        $countArt = $this->migrateAdvancedResourceTemplate($connection, $cvToScheme);
        $this->reportSettings($connection, $cvToScheme, $logger);

        $logger->notice(
            'Migration done: the thesaurus data type was added to {rtp} resource template properties and {art} advanced resource template configs.', // @translate
            ['rtp' => $countRtp, 'art' => $countArt]
        );
    }

    /**
     * Map each thesaurus custom vocab (item set) id to its scheme id.
     */
    private function mapCustomVocabToScheme(Connection $connection, int $schemeClassId): array
    {
        $sql = <<<'SQL'
            SELECT cv.id AS cv_id, (
                SELECT i.id
                FROM resource i
                INNER JOIN item_item_set iis ON iis.item_id = i.id
                WHERE iis.item_set_id = cv.item_set_id
                    AND i.resource_class_id = :scheme_class_id
                LIMIT 1
            ) AS scheme_id
            FROM custom_vocab cv
            WHERE cv.item_set_id IS NOT NULL
            SQL;
        return array_filter($connection->executeQuery($sql, ['scheme_class_id' => $schemeClassId])->fetchAllKeyValue());
    }

    /**
     * Insert "thesaurus:{schemeId}" before each thesaurus custom vocab type.
     */
    private function addThesaurusTypes(array $list, array $cvToScheme, bool &$changed): array
    {
        $new = [];
        foreach ($list as $type) {
            if (preg_match('/^customvocab:(\d+)$/', (string) $type, $m)
                && isset($cvToScheme[(int) $m[1]])
            ) {
                $thesaurusType = 'thesaurus:' . $cvToScheme[(int) $m[1]];
                if (!in_array($thesaurusType, $list, true) && !in_array($thesaurusType, $new, true)) {
                    $new[] = $thesaurusType;
                    $changed = true;
                }
            }
            $new[] = $type;
        }
        return $new;
    }

    private function migrateResourceTemplateProperties(Connection $connection, array $cvToScheme): int
    {
        $rows = $connection->executeQuery(
            "SELECT id, data_type FROM resource_template_property WHERE data_type LIKE '%customvocab:%'"
        )->fetchAllKeyValue();
        $sqlUpdate = 'UPDATE resource_template_property SET data_type = :data_type WHERE id = :id';
        $count = 0;
        foreach ($rows as $id => $dataType) {
            $list = json_decode((string) $dataType, true);
            if (!is_array($list)) {
                continue;
            }
            $changed = false;
            $new = $this->addThesaurusTypes($list, $cvToScheme, $changed);
            if ($changed) {
                $connection->executeStatement($sqlUpdate, ['data_type' => json_encode($new), 'id' => (int) $id]);
                ++$count;
            }
        }
        return $count;
    }

    /**
     * Migrate the "o:data_type" of the module Advanced Resource Template, when
     * present.
     */
    private function migrateAdvancedResourceTemplate(Connection $connection, array $cvToScheme): int
    {
        $hasTable = $connection->executeQuery("SHOW TABLES LIKE 'resource_template_property_data'")->fetchOne();
        if (!$hasTable) {
            return 0;
        }
        $rows = $connection->executeQuery(
            "SELECT id, data FROM resource_template_property_data WHERE data LIKE '%customvocab:%'"
        )->fetchAllKeyValue();
        $sqlUpdate = 'UPDATE resource_template_property_data SET data = :data WHERE id = :id';
        $count = 0;
        foreach ($rows as $id => $data) {
            $decoded = json_decode((string) $data, true);
            if (!is_array($decoded)
                || !isset($decoded['o:data_type'])
                || !is_array($decoded['o:data_type'])
            ) {
                continue;
            }
            $changed = false;
            $decoded['o:data_type'] = $this->addThesaurusTypes($decoded['o:data_type'], $cvToScheme, $changed);
            if ($changed) {
                $connection->executeStatement($sqlUpdate, ['data' => json_encode($decoded), 'id' => (int) $id]);
                ++$count;
            }
        }
        return $count;
    }

    /**
     * Report the settings and site settings that reference a thesaurus custom
     * vocab, so they can be reviewed manually (the meaning is module specific).
     */
    private function reportSettings(Connection $connection, array $cvToScheme, $logger): void
    {
        $cvNames = [];
        foreach (array_keys($cvToScheme) as $cvId) {
            $cvNames[] = 'customvocab:' . $cvId;
        }
        foreach (['setting', 'site_setting'] as $table) {
            $rows = $connection->executeQuery(
                "SELECT id, value FROM $table WHERE value LIKE '%customvocab:%'"
            )->fetchAllKeyValue();
            foreach ($rows as $id => $value) {
                foreach ($cvNames as $cvName) {
                    if (mb_strpos((string) $value, $cvName) !== false) {
                        $logger->warn(
                            'The setting "{id}" (table {table}) references the thesaurus custom vocab "{cv}". Review it manually to use the thesaurus data type if wanted.', // @translate
                            ['id' => $id, 'table' => $table, 'cv' => $cvName]
                        );
                    }
                }
            }
        }
    }
}
