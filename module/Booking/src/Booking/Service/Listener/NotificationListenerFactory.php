<?php

namespace Booking\Service\Listener;

use Backend\Service\MailService as BackendMailService;
use Base\Manager\OptionManager;
use Base\View\Helper\DateRange;
use Base\View\Helper\PriceFormatPlain;
use Booking\Manager\Booking\BillManager;
use Booking\Manager\ReservationManager;
use Interop\Container\ContainerInterface;
use Service\Service\BookingInterestService;
use Square\Manager\SquareManager;
use User\Manager\UserManager;
use User\Service\MailService as UserMailService;
use Zend\I18n\Translator\TranslatorInterface;
use Zend\ServiceManager\Factory\FactoryInterface as V3FactoryInterface;
use Zend\ServiceManager\FactoryInterface as V2FactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;
use Zend\View\HelperPluginManager;

/**
 * Factory for NotificationListener.
 *
 * Works with both ZF2 (createService) and ZF3 (__invoke) styles.
 */
class NotificationListenerFactory implements V2FactoryInterface, V3FactoryInterface
{
    /**
     * ZF3-style factory (__invoke).
     *
     * @param ContainerInterface $container
     * @param string             $requestedName
     * @param null|array         $options
     * @return NotificationListener
     */
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        return $this->doCreate($container);
    }

    /**
     * ZF2-style factory (createService).
     *
     * @param ServiceLocatorInterface $serviceLocator
     * @return NotificationListener
     */
    public function createService(ServiceLocatorInterface $serviceLocator)
    {
        // Some SM versions wrap the real container, unwrap if needed
        if (method_exists($serviceLocator, 'getServiceLocator')) {
            $container = $serviceLocator->getServiceLocator();
        } else {
            $container = $serviceLocator;
        }

        return $this->doCreate($container);
    }

    /**
     * Actual construction logic shared by both factory styles.
     *
     * @param ContainerInterface|ServiceLocatorInterface $container
     * @return NotificationListener
     */
    private function doCreate($container)
    {
        /** @var OptionManager $optionManager */
        $optionManager = $container->get('Base\Manager\OptionManager');

        /** @var ReservationManager $reservationManager */
        $reservationManager = $container->get('Booking\Manager\ReservationManager');

        /** @var SquareManager $squareManager */
        $squareManager = $container->get('Square\Manager\SquareManager');

        /** @var UserManager $userManager */
        $userManager = $container->get('User\Manager\UserManager');

        /** @var UserMailService $userMailService */
        $userMailService = $container->get('User\Service\MailService');

        /** @var BackendMailService $backendMailService */
        $backendMailService = $container->get('Backend\Service\MailService');

        /** @var HelperPluginManager $viewHelperManager */
        $viewHelperManager = $container->get('ViewHelperManager');

        /** @var \Zend\I18n\View\Helper\DateFormat $dateFormatHelper */
        $dateFormatHelper = $viewHelperManager->get('dateFormat');

        /** @var DateRange $dateRangeHelper */
        $dateRangeHelper = $viewHelperManager->get('dateRange');

        // Translator – use MvcTranslator alias which ep-3 normally registers
        /** @var TranslatorInterface $translator */
        if ($container->has('MvcTranslator')) {
            $translator = $container->get('MvcTranslator');
        } else {
            // fallback (for older setups) – should still implement TranslatorInterface
            $translator = $container->get('translator');
        }

        // OPTIONAL: BookingInterestService (used for “free slot” notifications).
        // If anything goes wrong here, we quietly fall back to null so that
        // bookings / normal mails still work.
        $bookingInterestService = null;
        try {
            if ($container->has(BookingInterestService::class)) {
                /** @var BookingInterestService $bookingInterestService */
                $bookingInterestService = $container->get(BookingInterestService::class);
            } elseif ($container->has('Service\Service\BookingInterestService')) {
                $bookingInterestService = $container->get('Service\Service\BookingInterestService');
            }
        } catch (\Throwable $e) {
            // Do NOT break booking flow if this optional service is misconfigured
            error_log(
                'SSA: BookingInterestService not available in NotificationListenerFactory: '
                . $e->getMessage()
            );
            $bookingInterestService = null;
        }

        // OPTIONAL: Booking bill manager – may be null
        $bookingBillManager = null;
        if ($container->has('Booking\Manager\Booking\BillManager')) {
            /** @var BillManager $bookingBillManager */
            $bookingBillManager = $container->get('Booking\Manager\Booking\BillManager');
        }

        // OPTIONAL: plain price formatter helper – may be null
        $priceFormatHelper = null;
        try {
            if ($viewHelperManager->has('priceFormatPlain')) {
                /** @var PriceFormatPlain $priceFormatHelper */
                $priceFormatHelper = $viewHelperManager->get('priceFormatPlain');
            }
        } catch (\Throwable $e) {
            // not critical
            $priceFormatHelper = null;
        }

        // Finally build the listener
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
            $bookingInterestService,   // may be null – code in onCancelSingle checks this
            $bookingBillManager,
            $priceFormatHelper
        );
    }
}
