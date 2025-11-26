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
        $bookingService = new BookingService(
            $sm->get('Base\Manager\OptionManager'),
            $sm->get('Booking\Manager\BookingManager'),
            $sm->get('Booking\Manager\Booking\BillManager'),
            $sm->get('Booking\Manager\ReservationManager'),
            $sm->get('Square\Manager\SquarePricingManager'),
            $sm->get('ViewHelperManager'),
            $sm->get('Zend\Db\Adapter\Adapter')->getDriver()->getConnection()
        );

        try {
            /** @var EventManagerInterface $eventManager */
            $eventManager = $bookingService->getEventManager();

            /** @var \Booking\Service\Listener\NotificationListener $notificationListener */
            $notificationListener = $sm->get('Booking\Service\Listener\NotificationListener');

            // IMPORTANT: call attach() ON the listener
            $notificationListener->attach($eventManager);

            error_log('SSA: BookingServiceFactory attached NotificationListener');

        } catch (Throwable $e) {
            error_log('SSA: BookingServiceFactory could not attach NotificationListener: ' . $e->getMessage());
        }

        return $bookingService;
    }
}
