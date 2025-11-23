<?php
namespace Service\Factory;

use Service\Service\WhatsAppService;
use Zend\Http\Client;
use Zend\ServiceManager\FactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;

class WhatsAppServiceFactory implements FactoryInterface
{
    /**
     * @param ServiceLocatorInterface $sl
     * @return WhatsAppService
     */
    public function createService(ServiceLocatorInterface $sl)
    {
        $config = $sl->get('config');
        $client = new Client();

        return new WhatsAppService($client, $config);
    }
}
