<?php
namespace Thesaurus;

/**
 * @var Module $this
 * @var \Zend\ServiceManager\ServiceLocatorInterface $serviceLocator
 * @var string $newVersion
 * @var string $oldVersion
 *
 * @var \Doctrine\DBAL\Connection $connection
 * @var \Doctrine\ORM\EntityManager $entityManager
 * @var \Omeka\Api\Manager $api
 */
$services = $serviceLocator;
$settings = $services->get('Omeka\Settings');
$config = require dirname(dirname(__DIR__)) . '/config/module.config.php';
$connection = $services->get('Omeka\Connection');
$entityManager = $services->get('Omeka\EntityManager');
$plugins = $services->get('ControllerPluginManager');
$api = $plugins->get('api');
$space = strtolower(__NAMESPACE__);

if (version_compare($oldVersion, '3.0.4', '<')) {
    $sql = <<<SQL
CREATE TABLE term (
    id INT AUTO_INCREMENT NOT NULL,
    item_id INT NOT NULL,
    scheme_id INT NOT NULL,
    root_id INT NOT NULL,
    broader_id INT DEFAULT NULL,
    position INT DEFAULT NULL,
    INDEX IDX_A50FE78D126F525E (item_id),
    INDEX IDX_A50FE78D65797862 (scheme_id),
    INDEX IDX_A50FE78D79066886 (root_id),
    INDEX IDX_A50FE78D5646636A (broader_id),
    UNIQUE INDEX UNIQ_A50FE78D126F525E65797862 (item_id, scheme_id),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;
ALTER TABLE term ADD CONSTRAINT FK_A50FE78D126F525E FOREIGN KEY (item_id) REFERENCES item (id) ON DELETE CASCADE;
ALTER TABLE term ADD CONSTRAINT FK_A50FE78D65797862 FOREIGN KEY (scheme_id) REFERENCES item (id) ON DELETE CASCADE;
ALTER TABLE term ADD CONSTRAINT FK_A50FE78D79066886 FOREIGN KEY (root_id) REFERENCES term (id) ON DELETE CASCADE;
ALTER TABLE term ADD CONSTRAINT FK_A50FE78D5646636A FOREIGN KEY (broader_id) REFERENCES term (id) ON DELETE CASCADE;
SQL;
    $connection->exec($sql);
}
