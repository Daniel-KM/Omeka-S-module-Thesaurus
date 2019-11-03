<?php
namespace Thesaurus;

/*
 * Copyright Daniel Berthereau, 2019
 *
 * This software is governed by the CeCILL license under French law and abiding
 * by the rules of distribution of free software.  You can use, modify and/ or
 * redistribute the software under the terms of the CeCILL license as circulated
 * by CEA, CNRS and INRIA at the following URL "http://www.cecill.info".
 *
 * As a counterpart to the access to the source code and rights to copy, modify
 * and redistribute granted by the license, users are provided only with a
 * limited warranty and the software's author, the holder of the economic
 * rights, and the successive licensors have only limited liability.
 *
 * In this respect, the user's attention is drawn to the risks associated with
 * loading, using, modifying and/or developing or reproducing the software by
 * the user in light of its specific status of free software, that may mean that
 * it is complicated to manipulate, and that also therefore means that it is
 * reserved for developers and experienced professionals having in-depth
 * computer knowledge. Users are therefore encouraged to load and test the
 * software's suitability as regards their requirements in conditions enabling
 * the security of their systems and/or data to be ensured and, more generally,
 * to use and operate it in the same conditions as regards security.
 *
 * The fact that you are presently reading this means that you have had
 * knowledge of the CeCILL license and that you accept its terms.
 */

if (!class_exists(\Generic\AbstractModule::class)) {
    require file_exists(dirname(__DIR__) . '/Generic/AbstractModule.php')
        ? dirname(__DIR__) . '/Generic/AbstractModule.php'
        : __DIR__ . '/src/Generic/AbstractModule.php';
}

use Generic\AbstractModule;

/**
 * Thesaurus
 *
 * Allows to use standard thesaurus (ISO 25964 to describe documents.
 *
 * @copyright Daniel Berthereau, 2018-2019
 * @license http://www.cecill.info/licences/Licence_CeCILL_V2.1-en.txt
 */
class Module extends AbstractModule
{
    const NAMESPACE = __NAMESPACE__;

    protected function postInstall()
    {
        $this->installResources();
    }

    protected function installResources()
    {
        if (!class_exists(\Generic\InstallResources::class)) {
            require_once file_exists(dirname(__DIR__) . '/Generic/InstallResources.php')
                ? dirname(__DIR__) . '/Generic/InstallResources.php'
                : __DIR__ . '/src/Generic/InstallResources.php';
        }

        $services = $this->getServiceLocator();
        $installResources = new \Generic\InstallResources($services);
        $installResources = $installResources();

        // The original files may not be imported fully in Omeka S, so use a
        // simplified but full version of Skos.
        // @url https://lov.linkeddata.es/dataset/lov/vocabs/skos/versions/2009-08-18.n3
        $vocabulary = [
            'vocabulary' => [
                'o:namespace_uri' => 'http://www.w3.org/2004/02/skos/core#',
                'o:prefix' => 'skos',
                'o:label' => 'Simple Knowledge Organization System', // @translate
                'o:comment' => "An RDF vocabulary for describing the basic structure and content of concept schemes such as thesauri, classification schemes, subject heading lists, taxonomies, 'folksonomies', other types of controlled vocabulary, and also concept schemes embedded in glossaries and terminologies.", // @translate
            ],
            'strategy' => 'file',
            'file' => __DIR__ . '/data/vocabularies/skos_2009-08-18.ttl',
            'format' => 'turtle',
        ];
        $installResources->createVocabulary($vocabulary);

        // Create resource templates.
        $resourceTemplatePaths = [
            __DIR__ . '/data/resource-templates/Thesaurus_Concept.json',
        ];
        foreach ($resourceTemplatePaths as $filepath) {
            $installResources->createResourceTemplate($filepath);
        }
    }
}
