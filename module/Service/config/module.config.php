<?php

return array(
    'router' => array(
        'routes' => array(
            'service' => array(
                'type' => 'Literal',
                'options' => array(
                    'route' => '/service',
                    'defaults' => array(
                        'controller' => 'Service\Controller\Service',
                    ),
                ),
                'may_terminate' => false,
                'child_routes' => array(
                    'info' => array(
                        'type' => 'Literal',
                        'options' => array(
                            'route' => '/info',
                            'defaults' => array(
                                'action' => 'info',
                            ),
                        ),
                    ),
                    'help' => array(
                        'type' => 'Literal',
                        'options' => array(
                            'route' => '/help',
                            'defaults' => array(
                                'action' => 'help',
                            ),
                        ),
                    ),
                    'status' => array(
                        'type' => 'Literal',
                        'options' => array(
                            'route' => '/status',
                            'defaults' => array(
                                'action' => 'status',
                            ),
                        ),
                    ),
                ),
            ),
        ),
    ),

    'controllers' => array(
        'invokables' => array(
            'Service\Controller\Service' => 'Service\Controller\ServiceController',
        ),
    ),
    
    'service_manager' => [
        'factories' => [
            \Service\Service\BookingInterestService::class =>
                \Service\Factory\BookingInterestServiceFactory::class,
            \Service\Service\WhatsAppService::class =>
                \Service\Factory\WhatsAppServiceFactory::class,
        ],
    ],

    'view_manager' => array(
        'template_path_stack' => array(
            __DIR__ . '/../view',
        ),
    ),
);