<?php

namespace Booking\Service\Listener;

use Booking\Manager\Booking\BillManager;
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
        // --- Core required services ---
        $optionManager      = $serviceLocator->get('Base\Manager\OptionManager');
        $reservationManager = $serviceLocator->get('Booking\Manager\ReservationManager');
        $squareManager      = $serviceLocator->get('Square\Manager\SquareManager');
        $userManager        = $serviceLocator->get('User\Manager\UserManager');
        $userMailService    = $serviceLocator->get('User\Service\MailService');
        $backendMailService = $serviceLocator->get('Backend\Service\MailService');
        $viewHelperManager  = $serviceLocator->get('ViewHelperManager');
        $translator         = $serviceLocator->get('Translator');

        $dateFormatHelper   = $viewHelperManager->get('DateFormat');
        $dateRangeHelper    = $viewHelperManager->get('DateRange');

        // --- Optional / new services: make them robust ---

        // Interest in days
        $bookingInterestService = null;
        try {
            $bookingInterestService = $serviceLocator->get(BookingInterestService::class);
        } catch (\Throwable $e) {
            // If not configured, we just skip interest notifications
            error_log('SSA: BookingInterestService not available in NotificationListenerFactory: ' . $e->getMessage());
            $bookingInterestService = null;
        }

        // Bill manager for additional pricing-related notifications
        $bookingBillManager = null;
        try {
            /** @var BillManager $bookingBillManager */
            $bookingBillManager = $serviceLocator->get('Booking\Manager\Booking\BillManager');
        } catch (\Throwable $e) {
            error_log('SSA: Booking\\Manager\\Booking\\BillManager not available: ' . $e->getMessage());
            $bookingBillManager = null;
        }

        // Helper for formatting prices in plain text
        $priceFormatHelper = null;
        try {
            $priceFormatHelper = $viewHelperManager->get('PriceFormatPlain');
        } catch (\Throwable $e) {
            error_log('SSA: PriceFormatPlain helper not available: ' . $e->getMessage());
            $priceFormatHelper = null;
        }

        // --- Create the listener with all dependencies ---

        $listener = new NotificationListener(
            $optionManager,
            $reservationManager,
            $squareManager,
            $userManager,
            $userMailService,
            $backendMailService,
            $dateFormatHelper,
            $dateRangeHelper,
            $translator,
            $bookingInterestService,
            $bookingBillManager,
            $priceFormatHelper
        );

        error_log('SSA: NotificationListenerFactory created NotificationListener instance');

        return $listener;
    }
}
