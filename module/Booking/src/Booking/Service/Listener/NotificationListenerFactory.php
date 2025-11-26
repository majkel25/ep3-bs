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
            // Core managers
            $serviceLocator->get('Base\Manager\OptionManager'),
            $serviceLocator->get('Booking\Manager\ReservationManager'),
            $serviceLocator->get('Square\Manager\SquareManager'),
            $serviceLocator->get('User\Manager\UserManager'),

            // Mail services
            $serviceLocator->get('User\Service\MailService'),
            $serviceLocator->get('Backend\Service\MailService'),

            // View helpers
            $serviceLocator->get('ViewHelperManager')->get('DateFormat'),
            $serviceLocator->get('ViewHelperManager')->get('DateRange'),

            // Translator
            $serviceLocator->get('Translator'),

            // NEW: service that handles "interest in a day"
            $serviceLocator->get(BookingInterestService::class),

            // NEW: Bill manager and price formatter, added to the listener constructor
            $serviceLocator->get('Booking\Manager\Booking\BillManager'),
            $serviceLocator->get('ViewHelperManager')->get('PriceFormatPlain')
        );
    }
}
