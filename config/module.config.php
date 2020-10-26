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
    ],
    'view_helpers' => [
        'invokables' => [
            'linkTerm' => View\Helper\LinkTerm::class,
        ],
        'factories' => [
            'thesaurus' => Service\ViewHelper\ThesaurusFactory::class,
            'thesaurusItem' => Service\ViewHelper\ThesaurusItemFactory::class,
        ],
    ],
    'block_layouts' => [
        'invokables' => [
            'thesaurus' => Site\BlockLayout\Thesaurus::class,
        ],
    ],
    'form_elements' => [
        'invokables' => [
            Form\ConfigForm::class => Form\ConfigForm::class,
            Form\ConvertForm::class => Form\ConvertForm::class,
            Form\ThesaurusFieldset::class => Form\ThesaurusFieldset::class,
        ],
    ],
    'controllers' => [
        'invokables' => [
            Controller\Admin\ThesaurusController::class => Controller\Admin\ThesaurusController::class,
        ],
    ],
    'controller_plugins' => [
        'factories' => [
            'thesaurus' => Service\ControllerPlugin\ThesaurusFactory::class,
        ],
    ],
    'router' => [
        'routes' => [
            'admin' => [
                'child_routes' => [
                    'thesaurus' => [
                        'type' => \Laminas\Router\Http\Segment::class,
                        'options' => [
                            'route' => '/thesaurus[/:action]',
                            'constraints' => [
                                'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                            ],
                            'defaults' => [
                                '__NAMESPACE__' => 'Thesaurus\Controller\Admin',
                                'controller' => Controller\Admin\ThesaurusController::class,
                                'action' => 'index',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'navigation' => [
        'AdminModule' => [
            [
                'label' => 'Thesaurus', // @translate
                'route' => 'admin/thesaurus',
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
