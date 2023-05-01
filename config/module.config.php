<?php declare(strict_types=1);

namespace Thesaurus;

return [
    'api_adapters' => [
        'invokables' => [
            'terms' => Api\Adapter\TermAdapter::class,
        ],
    ],
    'entity_manager' => [
        'mapping_classes_paths' => [
            dirname(__DIR__) . '/src/Entity',
        ],
        'proxy_paths' => [
            dirname(__DIR__) . '/data/doctrine-proxies',
        ],
    ],
    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view',
        ],
        'strategies' => [
            'ViewJsonStrategy',
        ],
        /*
        'controller_map' => [
            Controller\Admin\ThesaurusController::class => 'omeka/admin/item',
        ],
        */
    ],
    'view_helpers' => [
        'invokables' => [
            'linkTerm' => View\Helper\LinkTerm::class,
        ],
        'factories' => [
            'thesaurus' => Service\ViewHelper\ThesaurusFactory::class,
        ],
    ],
    'block_layouts' => [
        'invokables' => [
            'thesaurus' => Site\BlockLayout\Thesaurus::class,
        ],
    ],
    'form_elements' => [
        'invokables' => [
            Form\Element\OptionalRadio::class => Form\Element\OptionalRadio::class,
            Form\ConfirmAllForm::class => Form\ConfirmAllForm::class,
            Form\ConvertForm::class => Form\ConvertForm::class,
            Form\SettingsFieldset::class => Form\SettingsFieldset::class,
            Form\ThesaurusFieldset::class => Form\ThesaurusFieldset::class,
            Form\UpdateConceptsForm::class => Form\UpdateConceptsForm::class,
        ],
        'factories' => [
            Form\Element\CustomVocabSelect::class => Service\Form\Element\CustomVocabSelectFactory::class,
            Form\Element\ThesaurusSelect::class => Service\Form\Element\ThesaurusSelectFactory::class,
        ],
        'aliases' => [
            \CustomVocab\Form\Element\CustomVocabSelect::class => Form\Element\CustomVocabSelect::class,
        ],
    ],
    'controllers' => [
        'factories' => [
            Controller\Admin\ThesaurusController::class => Service\Controller\Admin\ThesaurusControllerFactory::class,
        ],
    ],
    'controller_plugins' => [
        'factories' => [
            'thesaurus' => Service\ControllerPlugin\ThesaurusFactory::class,
        ],
    ],
    'listeners' => [
        Mvc\MvcListeners::class,
    ],
    'service_manager' => [
        'invokables' => [
            Mvc\MvcListeners::class => Mvc\MvcListeners::class,
        ],
    ],
    'router' => [
        'routes' => [
            'admin' => [
                'child_routes' => [
                    'thesaurus' => [
                        'type' => \Laminas\Router\Http\Literal::class,
                        'options' => [
                            'route' => '/thesaurus',
                            'defaults' => [
                                '__NAMESPACE__' => 'Thesaurus\Controller\Admin',
                                '__ADMIN__' => true,
                                'controller' => Controller\Admin\ThesaurusController::class,
                                'action' => 'browse',
                            ],
                        ],
                        'may_terminate' => true,
                        'child_routes' => [
                            'default' => [
                                'type' => \Laminas\Router\Http\Segment::class,
                                'options' => [
                                    'route' => '/:action',
                                    'constraints' => [
                                        'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                                    ],
                                    'defaults' => [
                                        'action' => 'browse',
                                    ],
                                ],
                            ],
                            'id' => [
                                'type' => \Laminas\Router\Http\Segment::class,
                                'options' => [
                                    'route' => '/:id[/:action]',
                                    'constraints' => [
                                        'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                                        'id' => '\d+',
                                    ],
                                    'defaults' => [
                                        'action' => 'show',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'navigation' => [
        'AdminResource' => [
            'thesaurus' => [
                'label' => 'Thesaurus', // @translate
                // No, it is already used for navigation.
                // 'class' => 'o-icon- fa-sitemap',
                'class' => 'o-icon- fa-project-diagram',
                'route' => 'admin/thesaurus/default',
                'action' => 'browse',
                'resource' => Controller\Admin\ThesaurusController::class,
                'privilege' => 'browse',
                'pages' => [
                    [
                        'route' => 'admin/thesaurus/id',
                        'controller' => Controller\Admin\ThesaurusController::class,
                        'visible' => false,
                    ],
                    [
                        'route' => 'admin/thesaurus/default',
                        'controller' => Controller\Admin\ThesaurusController::class,
                        'visible' => false,
                    ],
                    // TODO Clariflying place of the old tool to build a static flat thesaurus.
                 ],
            ],
        ],
    ],
    'translator' => [
        'translation_file_patterns' => [
            [
                'type' => 'gettext',
                'base_dir' => dirname(__DIR__) . '/language',
                'pattern' => '%s.mo',
                'text_domain' => null,
            ],
        ],
    ],
    'thesaurus' => [
        // Keep empty config for automatic management.
        'config' => [
            // Hidden options to store resource classes and templates.
            'thesaurus_skos_scheme_class_id' => null,
            'thesaurus_skos_concept_class_id' => null,
            'thesaurus_skos_scheme_template_id' => null,
            'thesaurus_skos_concept_template_id' => null,
        ],
        'settings' => [
            'thesaurus_property_descriptor' => 'skos:prefLabel',
            'thesaurus_property_path' => '',
            'thesaurus_property_ascendance' => '',
            'thesaurus_separator' => \Thesaurus\Module::SEPARATOR,
            'thesaurus_select_display' => 'ascendance',
        ],
        'block_settings' => [
            'thesaurus' => [
                'heading' => '',
                'item' => '',
                'type' => 'tree',
                'link' => 'both',
                'term' => 'dcterms:subject',
                'hideIfEmpty' => false,
                'expanded' => 0,
                'template' => '',
            ],
        ],
    ],
];
