<?php

namespace Booking\Service\Listener;

use Backend\Service\MailService as BackendMailService;
use Base\Manager\OptionManager;
use Base\View\Helper\DateFormat;
use Base\View\Helper\DateRange;
use Base\View\Helper\PriceFormatPlain;
use Booking\Manager\Booking\BillManager;
use Booking\Manager\ReservationManager;
use Service\Service\BookingInterestService;
use Square\Manager\SquareManager;
use User\Manager\UserManager;
use User\Service\MailService as UserMailService;
use Zend\ServiceManager\FactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;
use Zend\View\HelperPluginManager;
use Zend\I18n\Translator\TranslatorInterface;

class NotificationListenerFactory implements FactoryInterface
{
    /**
     * {@inheritdoc}
     */
    public function createService(ServiceLocatorInterface $sl)
    {
        /** @var OptionManager $optionManager */
        $optionManager   = $sl->get(OptionManager::class);

        /** @var SquareManager $squareManager */
        $squareManager   = $sl->get('Square\Manager\SquareManager');

        /** @var ReservationManager $reservationManager */
        $reservationManager = $sl->get('Booking\Manager\ReservationManager');

        /** @var UserManager $userManager */
        $userManager     = $sl->get('User\Manager\UserManager');

        /** @var UserMailService $userMailService */
        $userMailService = $sl->get(UserMailService::class);

        /** @var BackendMailService $backendMailService */
        $backendMailService = $sl->get(BackendMailService::class);

        /** @var HelperPluginManager $viewHelperManager */
        $viewHelperManager = $sl->get('ViewHelperManager');

        /** @var DateFormat $dateFormatHelper */
        $dateFormatHelper = $viewHelperManager->get('DateFormat');

        /** @var DateRange $dateRangeHelper */
        $dateRangeHelper  = $viewHelperManager->get('DateRange');

        /** @var PriceFormatPlain $priceFormatHelper */
        $priceFormatHelper = $viewHelperManager->get('PriceFormatPlain');

        /** @var TranslatorInterface $translator */
        $translator = $sl->get('MvcTranslator');

        // Optional services – only if present
        $bookingInterestService = null;
        if ($sl->has(BookingInterestService::class)) {
            $bookingInterestService = $sl->get(BookingInterestService::class);
        }

        $bookingBillManager = null;
        if ($sl->has(BillManager::class)) {
            $bookingBillManager = $sl->get(BillManager::class);
        }

        return new NotificationListener(
            $optionManager,
            $squareManager,
            $reservationManager,
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
