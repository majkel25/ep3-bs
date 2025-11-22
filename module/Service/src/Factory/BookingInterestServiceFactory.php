<?php
namespace Service\Factory;

use Service\Service\BookingInterestService;
use Service\Service\WhatsAppService;
use Zend\Db\Adapter\Adapter;
use Zend\Mail\Transport\Sendmail;
use Zend\Mail\Transport\Smtp;
use Zend\Mail\Transport\SmtpOptions;
use Zend\ServiceManager\FactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;

class BookingInterestServiceFactory implements FactoryInterface
{
    /**
     * @param ServiceLocatorInterface $sl
     * @return BookingInterestService
     */
    public function createService(ServiceLocatorInterface $sl)
    {
        /** @var Adapter $dbAdapter */
        $dbAdapter = $sl->get('Zend\Db\Adapter\Adapter');

        // Load mail configuration
        $config  = $sl->get('config');
        $mailCfg = isset($config['mail']) ? $config['mail'] : array();

        // ---------- Build mail transport locally (no external services needed) ----------
        $type = isset($mailCfg['type']) ? strtolower($mailCfg['type']) : 'sendmail';

        if ($type === 'smtp' || $type === 'smtp-tls') {

            $host = isset($mailCfg['host']) ? $mailCfg['host'] : 'localhost';
            $port = isset($mailCfg['port']) ? (int)$mailCfg['port'] : 25;

            $optionsArray = array(
                'name' => $host,
                'host' => $host,
                'port' => $port,
            );

            // Authentication
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
            // Default: sendmail, same as original ep3-bs behaviour
            $mailTransport = new Sendmail();
        }
        // ---------- end mail transport build ----------

        // WhatsApp is OPTIONAL – if it is not registered, we just pass null
        $wa = null;
        if ($sl->has(WhatsAppService::class)) {
            try {
                /** @var WhatsAppService $wa */
                $wa = $sl->get(WhatsAppService::class);
            } catch (\Exception $e) {
                $wa = null;
            }
        }

        return new BookingInterestService(
            $dbAdapter,
            $mailTransport,
            $mailCfg,
            $wa
        );
    }
}
