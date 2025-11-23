<?php

namespace Service\Factory;

use Service\Service\BookingInterestService;
use Service\Service\WhatsAppService;
use Zend\Db\Adapter\Adapter;
use Zend\Mail\Transport\TransportInterface;
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
        /** @var Adapter $db */
        $db = $sl->get('Zend\Db\Adapter\Adapter');

        /** @var TransportInterface $mail */
        $mail = $sl->get('Zend\Mail\Transport\TransportInterface');

        $config  = $sl->get('config');
        $mailCfg = isset($config['mail']) ? $config['mail'] : array();

        /** @var WhatsAppService $wa */
        $wa = null;
        if ($sl->has(WhatsAppService::class)) {
            try {
                $wa = $sl->get(WhatsAppService::class);
            } catch (\Exception $e) {
                $wa = null;
            }
        }

        return new BookingInterestService($db, $mail, $mailCfg, $wa);
    }
}
