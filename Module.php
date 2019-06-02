<?php
namespace Thesaurus;

use Omeka\Module\AbstractModule;

/**
 * Thesaurus
 *
 * Allows to use standard thesaurus (ISO 25964 to describe documents.
 *
 * @copyright Daniel Berthereau, 2018
 * @license http://www.cecill.info/licences/Licence_CeCILL_V2.1-en.txt
 */
class Module extends AbstractModule
{
    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }
}
