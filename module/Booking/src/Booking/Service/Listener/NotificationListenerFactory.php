<?php

namespace Booking\Service\Listener;

use Zend\ServiceManager\FactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;

class NotificationListenerFactory implements FactoryInterface
{
    public function createService(ServiceLocatorInterface $sm)
    {
        try {
            return new NotificationListener(
                $sm->get('User\Manager\UserManager'),
                $sm->get('User\Service\MailService'),
                $sm->get('Square\Manager\SquareManager'),
                $sm->get('Booking\Manager\ReservationManager'),
                $sm->get('Booking\Manager\BookingInterestManager')
            );

        } catch (\Throwable $e) {
            header('Content-Type: text/plain; charset=utf-8');
            echo "DEBUG: exception inside NotificationListenerFactory\n\n";
            echo get_class($e) . ': ' . $e->getMessage() . "\n\n";
            echo $e->getTraceAsString();
            exit;
        }
    }
}