<?php

namespace Service\Factory;

use Interop\Container\ContainerInterface;
use Service\Service\BookingInterestService;
use Service\Service\WhatsAppService;
use Zend\Db\Adapter\Adapter;
use Zend\Mail\Transport\Sendmail;
use Zend\Mail\Transport\Smtp;
use Zend\Mail\Transport\SmtpOptions;
use Zend\Mail\Transport\TransportInterface;
use Zend\ServiceManager\Factory\FactoryInterface as V3FactoryInterface;
use Zend\ServiceManager\FactoryInterface as V2FactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;

class BookingInterestServiceFactory implements V2FactoryInterface, V3FactoryInterface
{
    /**
     * ZF3-style factory (used by newer ServiceManager).
     *
     * @param ContainerInterface $container
     * @param string             $requestedName
     * @param null|array         $options
     * @return BookingInterestService
     */
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        return $this->doCreate($container);
    }

    /**
     * ZF2-style factory (used by older ServiceManager).
     *
     * @param ServiceLocatorInterface $serviceLocator
     * @return BookingInterestService
     */
    public function createService(ServiceLocatorInterface $serviceLocator)
    {
        // Some ZF2 setups wrap the real container, so unwrap if needed
        if (method_exists($serviceLocator, 'getServiceLocator')) {
            $container = $serviceLocator->getServiceLocator();
        } else {
            $container = $serviceLocator;
        }

        return $this->doCreate($container);
    }

    /**
     * Actual construction logic shared by both factory styles.
     *
     * @param ContainerInterface|ServiceLocatorInterface $container
     * @return BookingInterestService
     */
    private function doCreate($container)
    {
        /** @var Adapter $db */
        $db = $container->get('Zend\Db\Adapter\Adapter');

        // Mail configuration
        $config  = $container->get('config');
        $mailCfg = isset($config['mail']) ? $config['mail'] : array();

        // Build mail transport  (sendmail / smtp / smtp-tls)
        $type = isset($mailCfg['type']) ? strtolower($mailCfg['type']) : 'sendmail';

        if ($type === 'smtp' || $type === 'smtp-tls') {

            $host = isset($mailCfg['host']) ? $mailCfg['host'] : 'localhost';
            $port = isset($mailCfg['port']) ? (int)$mailCfg['port'] : 25;

            $optionsArray = array(
                'name' => $host,
                'host' => $host,
                'port' => $port,
            );

            if (!empty($mailCfg['user'])) {
                $optionsArray['connection_class'] =
                    !empty($mailCfg['auth']) ? $mailCfg['auth'] : 'login';

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
            // Default: Sendmail
            $mailTransport = new Sendmail();
        }

        // WhatsApp is optional
        $whatsApp = null;
        if ($container->has(WhatsAppService::class)) {
            try {
                $whatsApp = $container->get(WhatsAppService::class);
            } catch (\Exception $e) {
                $whatsApp = null;
            }
        }

        return new BookingInterestService(
            $db,
            $mailTransport,
            $mailCfg,
            $whatsApp
        );
    }
}
