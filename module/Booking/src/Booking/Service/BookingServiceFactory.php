<?php

namespace Booking\Service;

use Zend\ServiceManager\FactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;
use Zend\EventManager\EventManagerInterface;
use Throwable;

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

        // Try to attach the notification listener, but NEVER let this
        // block creation of the BookingService itself.
        try {
            /** @var EventManagerInterface $eventManager */
            $eventManager = $bookingService->getEventManager();

            $notificationListener = $sm->get('Booking\Service\Listener\NotificationListener');

            // Attach listener to the booking events
            $eventManager->attach($notificationListener);
        } catch (Throwable $e) {
            // If anything goes wrong with the listener, we simply skip it.
            // You can later add logging here if you want to inspect $e.
        }

        return $bookingService;
    }
}
