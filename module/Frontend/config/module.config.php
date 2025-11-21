<?php

return array(
    'router' => array(
        'routes' => array(
            // Existing home / front page
            'frontend' => array(
                'type' => 'Literal',
                'options' => array(
                    'route' => '/',
                    'defaults' => array(
                        'controller' => 'Frontend\Controller\Index',
                        'action'     => 'index',
                    ),
                ),
            ),

            // NEW: AJAX endpoint to register interest in a day
            'interest-register' => array(
                'type'    => 'Literal',
                'options' => array(
                    'route'    => '/interest/register',
                    'defaults' => array(
                        'controller' => 'Frontend\Controller\Interest',
                        'action'     => 'register',
                    ),
                ),
            ),
        ),
    ),

    'controllers' => array(
        'invokables' => array(
            'Frontend\Controller\Index'    => 'Frontend\Controller\IndexController',
            'Frontend\Controller\Interest' => 'Frontend\Controller\InterestController', // NEW
        ),
    ),

    'view_manager' => array(
        'template_path_stack' => array(
            __DIR__ . '/../view',
        ),
        // Needed so JsonModel responses from InterestController are rendered as JSON
        'strategies' => array(
            'ViewJsonStrategy',
        ),
    ),
);
