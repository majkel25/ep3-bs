<?php

namespace Booking\Service;

use Zend\ServiceManager\FactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;
use Zend\EventManager\EventManagerInterface;

class BookingServiceFactory implements FactoryInterface
{
    /**
     * {@inheritdoc}
     */
    public function createService(ServiceLocatorInterface $sm)
    {
        // Create the core BookingService with all required dependencies
        $bookingService = new BookingService(
            $sm->get('Base\Manager\OptionManager'),
            $sm->get('Booking\Manager\BookingManager'),
            $sm->get('Booking\Manager\Booking\BillManager'),
            $sm->get('Booking\Manager\ReservationManager'),
            $sm->get('Square\Manager\SquarePricingManager'),
            $sm->get('ViewHelperManager'),
            $sm->get('Zend\Db\Adapter\Adapter')->getDriver()->getConnection()
        );

        /** @var EventManagerInterface $eventManager */
        $eventManager = $bookingService->getEventManager();

        // This call MUST succeed – if it doesn’t, we want to see the error
        $notificationListener = $sm->get('Booking\Service\Listener\NotificationListener');
        $notificationListener->attach($eventManager);

        return $bookingService;
    }
}
