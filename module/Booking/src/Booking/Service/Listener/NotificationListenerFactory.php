<?php

namespace Booking\Service\Listener;

use Base\Manager\OptionManager;
use Base\View\Helper\DateRange;
use Base\View\Helper\PriceFormatPlain;
use Booking\Manager\Booking\BillManager;
use Booking\Manager\ReservationManager;
use Service\Service\BookingInterestService;
use Square\Manager\SquareManager;
use User\Manager\UserManager;
use User\Service\MailService as UserMailService;
use Zend\I18n\View\Helper\DateFormat;
use Zend\Mvc\I18n\Translator;
use Zend\ServiceManager\FactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;
use Backend\Service\MailService as BackendMailService;

class NotificationListenerFactory implements FactoryInterface
{
    public function createService(ServiceLocatorInterface $sm)
    {
        /** @var OptionManager $optionManager */
        $optionManager      = $sm->get('Base\Manager\OptionManager');
        /** @var ReservationManager $reservationManager */
        $reservationManager = $sm->get('Booking\Manager\ReservationManager');
        /** @var SquareManager $squareManager */
        $squareManager      = $sm->get('Square\Manager\SquareManager');
        /** @var UserManager $userManager */
        $userManager        = $sm->get('User\Manager\UserManager');
        /** @var UserMailService $userMailService */
        $userMailService    = $sm->get('User\Service\MailService');
        /** @var BackendMailService $backendMailService */
        $backendMailService = $sm->get('Backend\Service\MailService');
        /** @var DateFormat $dateFormatHelper */
        $dateFormatHelper   = $sm->get('ViewHelperManager')->get('DateFormat');
        /** @var DateRange $dateRangeHelper */
        $dateRangeHelper    = $sm->get('ViewHelperManager')->get('DateRange');
        /** @var Translator $translator */
        $translator         = $sm->get('Translator');
        /** @var BillManager $bookingBillManager */
        $bookingBillManager = $sm->get('Booking\Manager\Booking\BillManager');
        /** @var PriceFormatPlain $priceFormatHelper */
        $priceFormatHelper  = $sm->get('ViewHelperManager')->get('PriceFormatPlain');

        // Booking interest service (TRULY optional)
        /** @var BookingInterestService|null $bookingInterestService */
        /** @var BookingInterestService|null $bookingInterestService */
            try {
                // use the imported FQCN – this matches Service/module.config.php
                $bookingInterestService = $sm->get(BookingInterestService::class);
            } catch (\E$bookingInterestService = null;
            xception $e) {
                error_log("BookingInterestService unavailable: " . $e->getMessage());
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
            $bookingBillManager,
            $priceFormatHelper,
            $bookingInterestService
        );
    }
}
