<?php
// Michael 1 26-11-2025
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
        $bookingService = new BookingService(
            $sm->get('Base\Manager\OptionManager'),
            $sm->get('Booking\Manager\BookingManager'),
            $sm->get('Booking\Manager\Booking\BillManager'),
            $sm->get('Booking\Manager\ReservationManager'),
            $sm->get('Square\Manager\SquarePricingManager'),
            $sm->get('ViewHelperManager'),
            $sm->get('Zend\Db\Adapter\Adapter')->getDriver()->getConnection()
        );

        // ----------------- DEBUG BLOCK START -----------------
        try {
            /** @var EventManagerInterface $eventManager */
            $eventManager = $bookingService->getEventManager();

            /** @var \Booking\Service\Listener\NotificationListener $notificationListener */
            $notificationListener = $sm->get('Booking\Service\Listener\NotificationListener');

            // Attach the listener to the booking events
            $notificationListener->attach($eventManager);

       } catch (\Throwable $e) {
            // TEMPORARY: dump the real error to the browser and stop.
            header('Content-Type: text/plain; charset=utf-8');
            echo "DEBUG: exception while attaching NotificationListener\n\n";
            echo get_class($e) . ": " . $e->getMessage() . "\n\n";
            echo "In file: " . $e->getFile() . " on line " . $e->getLine() . "\n\n";
            echo $e->getTraceAsString();
            exit;
        }
        // ----------------- DEBUG BLOCK END -------------------

        return $bookingService;
    }
}
