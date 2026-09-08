<?php

namespace Booking\Service;

use Zend\ServiceManager\FactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;

class BookingSlotLimitServiceFactory implements FactoryInterface
{
    public function createService(ServiceLocatorInterface $sm)
    {
        return new BookingSlotLimitService(
            $sm->get('Zend\Db\Adapter\Adapter')
        );
    }
}
