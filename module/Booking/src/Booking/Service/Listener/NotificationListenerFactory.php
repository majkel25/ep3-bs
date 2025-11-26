<?php

namespace Booking\Service\Listener;

use Service\Service\BookingInterestService;
use Zend\ServiceManager\FactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;

/**
 * Factory for NotificationListener.
 */
class NotificationListenerFactory implements FactoryInterface
{
    /**
     * {@inheritdoc}
     */
    public function createService(ServiceLocatorInterface $serviceLocator)
    {
        return new NotificationListener(
            $serviceLocator->get('Base\Manager\OptionManager'),
            $serviceLocator->get('Booking\Manager\ReservationManager'),
            $serviceLocator->get('Square\Manager\SquareManager'),
            $serviceLocator->get('User\Manager\UserManager'),
            $serviceLocator->get('User\Service\MailService'),
            $serviceLocator->get('Backend\Service\MailService'),
            $serviceLocator->get('ViewHelperManager')->get('DateFormat'),
            $serviceLocator->get('ViewHelperManager')->get('DateRange'),
            $serviceLocator->get('Translator'),
            // NEW: service that handles “interest in a day”
            $serviceLocator->get(BookingInterestService::class)
        );
    }
}
