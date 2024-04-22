<?php declare(strict_types=1);

$loader = require dirname(__DIR__) . '/vendor/autoload.php';
$loader->addPsr4('ThesaurusTest\\', __DIR__ . '/ThesaurusTest/');

use OmekaTestHelper\Bootstrap;

Bootstrap::bootstrap(__DIR__);
Bootstrap::loginAsAdmin();

// Bootstrap module Commonto avoid to create many useless mocks.
Bootstrap::enableModule('Common');
// Only the service EasyMeta is required to install a module, because it is used
// in InstallResources constructor.
@require_once dirname(__DIR__, 2) . '/Common/src/Stdlib/EasyMeta.php';
@require_once dirname(__DIR__, 2) . '/Common/src/Service/Stdlib/EasyMetaFactory.php';
/** @var Laminas\ServiceManager\ServiceManager $services */
$services = Bootstrap::getApplication()->getServiceManager();
$services->setFactory('EasyMeta', \Common\Service\Stdlib\EasyMetaFactory::class);

@require_once dirname(__DIR__, 2) . '/Common/src/Stdlib/PsrInterpolateInterface.php';
@require_once dirname(__DIR__, 2) . '/Common/src/Stdlib/PsrInterpolateTrait.php';
@require_once dirname(__DIR__, 2) . '/Common/src/Stdlib/PsrMessage.php';

Bootstrap::enableModule('Thesaurus');
