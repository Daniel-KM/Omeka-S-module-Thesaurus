<?php
namespace Thesaurus;

return [
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
        ],
    ],
    'form_elements' => [
        'invokables' => [
            Form\ConvertForm::class => Form\ConvertForm::class,
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
                        'type' => \Zend\Router\Http\Segment::class,
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
];
