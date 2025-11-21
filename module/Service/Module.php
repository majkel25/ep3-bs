<?php

namespace Service;

use Service\Service\BookingInterestService;
use Service\Service\WhatsAppService;
use Zend\EventManager\EventInterface;
use Zend\Mail\Transport\Null as NullTransport;
use Zend\Mail\Transport\TransportInterface;
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

                // Existing WhatsApp service factory
                WhatsAppService::class => \Service\Factory\WhatsAppServiceFactory::class,

                // Booking-interest service factory
                BookingInterestService::class => function ($serviceManager) {

                    // DB adapter (standard ep3-bs service)
                    $dbAdapter = $serviceManager->get('Zend\Db\Adapter\Adapter');

                    // App config (for mail settings like address, etc.)
                    $config  = $serviceManager->get('Config');
                    $mailCfg = isset($config['mail']) ? $config['mail'] : array();

                    /** @var TransportInterface $mailTransport */
                    if ($serviceManager->has('mail.transport')) {
                        // Use the SAME transport as the existing booking emails
                        $mailTransport = $serviceManager->get('mail.transport');
                    } else {
                        // Safe fallback (no-op, but avoids 500s in misconfigured envs)
                        $mailTransport = new NullTransport();
                    }

                    // WhatsApp wrapper
                    $whatsApp = $serviceManager->get(WhatsAppService::class);

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
