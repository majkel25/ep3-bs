<?php

namespace Booking\Service\Listener;

use Backend\Service\MailService as BackendMailService;
use Base\Manager\OptionManager;
use Base\View\Helper\DateRange;
use Base\View\Helper\PriceFormatPlain;
use Booking\Manager\Booking\BillManager;
use Booking\Manager\ReservationManager;
use Service\Service\BookingInterestService;
use Square\Manager\SquareManager;
use User\Manager\UserManager;
use User\Service\MailService as UserMailService;
use Zend\I18n\Translator\TranslatorInterface;
use Zend\I18n\View\Helper\DateFormat;
use Zend\ServiceManager\FactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;

class NotificationListenerFactory implements FactoryInterface
{
    /**
     * ZF2 factory – builds NotificationListener with all dependencies,
     * including BookingInterestService.
     *
     * @param ServiceLocatorInterface $serviceLocator
     * @return NotificationListener
     */
    public function createService(ServiceLocatorInterface $serviceLocator)
    {
        // On this project the main ServiceManager is passed directly,
        // so no need to unwrap; but keep a variable for clarity.
        $sm = $serviceLocator;

        /** @var OptionManager $optionManager */
        $optionManager = $sm->get('Base\Manager\OptionManager');

        /** @var ReservationManager $reservationManager */
        $reservationManager = $sm->get('Booking\Manager\ReservationManager');

        /** @var SquareManager $squareManager */
        $squareManager = $sm->get('Square\Manager\SquareManager');

        /** @var UserManager $userManager */
        $userManager = $sm->get('User\Manager\UserManager');

        /** @var UserMailService $userMailService */
        $userMailService = $sm->get('User\Service\MailService');

        /** @var BackendMailService $backendMailService */
        $backendMailService = $sm->get('Backend\Service\MailService');

        $viewHelperManager = $sm->get('ViewHelperManager');

        /** @var DateFormat $dateFormatHelper */
        $dateFormatHelper = $viewHelperManager->get('dateFormat');

        /** @var DateRange $dateRangeHelper */
        $dateRangeHelper = $viewHelperManager->get('dateRange');

        /** @var TranslatorInterface $translator */
        $translator = $sm->get('translator');

        /** @var BillManager|null $bookingBillManager */
        $bookingBillManager = $sm->has('Booking\Manager\Booking\BillManager')
            ? $sm->get('Booking\Manager\Booking\BillManager')
            : null;

        /** @var PriceFormatPlain|null $priceFormatHelper */
        $priceFormatHelper = $viewHelperManager->get('priceFormatPlain');

        // NEW: wire BookingInterestService
        /** @var BookingInterestService|null $bookingInterestService */
        $bookingInterestService = null;
        if ($sm->has(BookingInterestService::class)) {
            $bookingInterestService = $sm->get(BookingInterestService::class);
        }

        return new NotificationListener(
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
    }
}
