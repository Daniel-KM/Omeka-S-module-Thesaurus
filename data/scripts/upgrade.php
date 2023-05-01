<?php declare(strict_types=1);

namespace Thesaurus;

use Omeka\Stdlib\Message;

/**
 * @var Module $this
 * @var \Laminas\ServiceManager\ServiceLocatorInterface $services
 * @var string $newVersion
 * @var string $oldVersion
 *
 * @var \Omeka\Api\Manager $api
 * @var \Omeka\View\Helper\Url $url
 * @var \Omeka\Settings\Settings $settings
 * @var \Doctrine\DBAL\Connection $connection
 * @var \Doctrine\ORM\EntityManager $entityManager
 * @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger
 */
$plugins = $services->get('ControllerPluginManager');
$url = $services->get('ViewHelperManager')->get('url');
$api = $plugins->get('api');
$settings = $services->get('Omeka\Settings');
$connection = $services->get('Omeka\Connection');
$messenger = $plugins->get('messenger');
$entityManager = $services->get('Omeka\EntityManager');

if (version_compare($oldVersion, '3.0.4', '<')) {
    $sql = <<<SQL
CREATE TABLE term (
    id INT AUTO_INCREMENT NOT NULL,
    item_id INT NOT NULL,
    scheme_id INT NOT NULL,
    root_id INT DEFAULT NULL,
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
    $connection->executeStatement($sql);
}

if (version_compare($oldVersion, '3.3.7.0', '<')) {
    $this->storeSchemeAndConceptIds();
}

if (version_compare($oldVersion, '3.3.8.0', '<')) {
    $message = new Message(
        'It is now possible to get thesaurus data for another item without rebuilding it.' // @translate
    );
    $messenger->addSuccess($message);
}

if (version_compare($oldVersion, '3.4.9', '<')) {
    $settings->set('thesaurus_property_descriptor', 'skos:prefLabel');
    $settings->set('thesaurus_property_path', '');
    $settings->set('thesaurus_property_ascendance', '');
    $settings->set('thesaurus_separator', ' :: ');
    $settings->set('thesaurus_select_display', 'ascendance');

    $message = new Message(
        'Many performance improvements have been implemented. Big thesaurus can be managed instantly.' // @translate
    );
    $messenger->addSuccess($message);

    $message = new Message(
        'It is now possible to import a file and to create a standard thesaurus with all linked resources.' // @translate
    );
    $messenger->addSuccess($message);

    $settings->set('easyadmin_interface', ['resource_public_view']);
    $message = new Message('%1$sNew setttings%2$s allow to store the path or the ascendance of each concept automatically or via the update button of the thesaurus.', // @translate
        sprintf('<a href="%s">', $url('admin/default', ['controller' => 'setting'], ['fragment' => 'thesaurus'])),
        '</a>'
    );
    $message->setEscapeHtml(false);
    $messenger->addSuccess($message);
}
