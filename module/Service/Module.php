<?php

namespace Service;

use Service\Service\BookingInterestService;
use Service\Service\WhatsAppService;
use Zend\EventManager\EventInterface;
use Zend\Mail\Transport\Sendmail;
use Zend\Mail\Transport\Smtp;
use Zend\Mail\Transport\SmtpOptions;
use Zend\ModuleManager\Feature\AutoloaderProviderInterface;
use Zend\ModuleManager\Feature\BootstrapListenerInterface;
use Zend\ModuleManager\Feature\ConfigProviderInterface;
use Zend\Mvc\MvcEvent;

class Module implements AutoloaderProviderInterface, BootstrapListenerInterface, ConfigProviderInterface
{
    public function getAutoloaderConfig()
    {
        return array(
            'Zend\Loader\StandardAutoloader' => array(
                'namespaces' => array(
                    __NAMESPACE__ => __DIR__ . '/src/' . __NAMESPACE__,
                ),
            ),
        );
    }

    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function getServiceConfig()
    {
        return array(
            'factories' => array(

                // Already existing WhatsApp service
                WhatsAppService::class => \Service\Factory\WhatsAppServiceFactory::class,

                // Booking-interest service – build our own mail transport
                BookingInterestService::class => function ($serviceManager) {

                    // Database adapter
                    $dbAdapter = $serviceManager->get('Zend\Db\Adapter\Adapter');

                    // Mail configuration (from global + local config)
                    $config  = $serviceManager->get('Config');
                    $mailCfg = isset($config['mail']) ? $config['mail'] : array();

                    // ---- build the mail transport from $mailCfg ----
                    $type = isset($mailCfg['type']) ? strtolower($mailCfg['type']) : 'sendmail';

                    if ($type === 'smtp' || $type === 'smtp-tls') {

                        $host = isset($mailCfg['host']) ? $mailCfg['host'] : 'localhost';
                        $port = isset($mailCfg['port']) ? (int)$mailCfg['port'] : 25;

                        $optionsArray = array(
                            'name' => $host,
                            'host' => $host,
                            'port' => $port,
                        );

                        // Authentication config
                        if (!empty($mailCfg['user'])) {
                            $optionsArray['connection_class'] = !empty($mailCfg['auth']) ? $mailCfg['auth'] : 'login';

                            $connCfg = array(
                                'username' => $mailCfg['user'],
                                'password' => isset($mailCfg['pw']) ? $mailCfg['pw'] : '',
                            );

                            if ($type === 'smtp-tls') {
                                $connCfg['ssl'] = 'tls';
                            }

                            $optionsArray['connection_config'] = $connCfg;
                        }

                        $options       = new SmtpOptions($optionsArray);
                        $mailTransport = new Smtp($options);

                    } else {
                        // Default: use Sendmail, which is what ep3-bs uses by default
                        $mailTransport = new Sendmail();
                    }
                    // ---- end mail transport build ----

                    // WhatsApp service (optional – do not break if it fails)
                    $whatsApp = null;

                    if ($serviceManager->has(WhatsAppService::class)) {
                        try {
                            $whatsApp = $serviceManager->get(WhatsAppService::class);
                        } catch (\Throwable $e) {
                            // Optional: log or ignore – we just skip WhatsApp notifications
                            // error_log('WhatsAppService creation failed: ' . $e->getMessage());
                            $whatsApp = null;
                        }
                    }

                    return new BookingInterestService(
                        $dbAdapter,
                        $mailTransport,
                        $mailCfg,
                        $whatsApp
                    );
                },
            ),
        );
    }

    public function onBootstrap(EventInterface $e)
    {
        $events = $e->getApplication()->getEventManager();
        $events->attach(MvcEvent::EVENT_ROUTE, array($this, 'onDispatch'));
    }

    public function onDispatch(MvcEvent $e)
    {
        $serviceManager = $e->getApplication()->getServiceManager();
        $optionManager  = $serviceManager->get('Base\Manager\OptionManager');

        if ($optionManager->get('service.maintenance', 'false') == 'true') {
            $userSessionManager = $serviceManager->get('User\Manager\UserSessionManager');

            $user = $userSessionManager->getSessionUser();

            if ($user) {
                if ($user->need('status') == 'admin') {
                    return;
                }

                $userSessionManager->logout();
            }

            $routeMatch = $e->getRouteMatch();

            if (!($routeMatch->getParam('controller') == 'User\Controller\Session'
                && $routeMatch->getParam('action') == 'login')
            ) {
                $routeMatch->setParam('controller', 'Service\Controller\Service');
                $routeMatch->setParam('action', 'status');
            }
        }
    }
}
