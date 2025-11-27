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
use Zend\ServiceManager\FactoryInterface as V2FactoryInterface;
use Zend\ServiceManager\Factory\FactoryInterface as V3FactoryInterface;
use Interop\Container\ContainerInterface;
use Zend\ServiceManager\ServiceLocatorInterface;

/**
 * Factory for Booking\Service\Listener\NotificationListener
 *
 * Works with both ZF2 (createService) and ZF3 (__invoke) ServiceManager.
 */
class NotificationListenerFactory implements V2FactoryInterface, V3FactoryInterface
{
    /**
     * ZF3-style factory.
     *
     * @param ContainerInterface $container
     * @param string             $requestedName
     * @param null|array         $options
     * @return NotificationListener
     */
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        return $this->create($container);
    }

    /**
     * ZF2-style factory.
     *
     * @param ServiceLocatorInterface $serviceLocator
     * @return NotificationListener
     */
    public function createService(ServiceLocatorInterface $serviceLocator)
    {
        // In ZF2, $serviceLocator may be a plugin manager; unwrap if needed.
        if (method_exists($serviceLocator, 'getServiceLocator')) {
            $container = $serviceLocator->getServiceLocator();
        } else {
            $container = $serviceLocator;
        }

        return $this->create($container);
    }

    /**
     * Shared construction logic.
     *
     * @param ContainerInterface $container
     * @return NotificationListener
     */
    private function create(ContainerInterface $container)
    {
        /** @var OptionManager $optionManager */
        $optionManager = $container->get(OptionManager::class);

        /** @var ReservationManager $reservationManager */
        $reservationManager = $container->get(ReservationManager::class);

        /** @var SquareManager $squareManager */
        $squareManager = $container->get(SquareManager::class);

        /** @var UserManager $userManager */
        $userManager = $container->get(UserManager::class);

        /** @var UserMailService $userMailService */
        $userMailService = $container->get(UserMailService::class);

        /** @var BackendMailService $backendMailService */
        $backendMailService = $container->get(BackendMailService::class);

        // View helpers
        $viewHelperManager = $container->get('ViewHelperManager');

        /** @var DateFormat $dateFormatHelper */
        $dateFormatHelper = $viewHelperManager->get(DateFormat::class);

        /** @var DateRange $dateRangeHelper */
        $dateRangeHelper = $viewHelperManager->get(DateRange::class);

        /** @var TranslatorInterface $translator */
        $translator = $container->get('MvcTranslator');

        // Optional: BookingInterestService (for “watch this day” notifications)
        $bookingInterestService = null;
        if ($container->has(BookingInterestService::class)) {
            $bookingInterestService = $container->get(BookingInterestService::class);
        }

        // Optional: BillManager (if you use billing info in notifications)
        $bookingBillManager = null;
        if ($container->has(BillManager::class)) {
            $bookingBillManager = $container->get(BillManager::class);
        }

        // Optional: plain price formatter
        $priceFormatHelper = null;
        if ($container->has(PriceFormatPlain::class)) {
            $priceFormatHelper = $container->get(PriceFormatPlain::class);
        }

        // This MUST always return an instance – otherwise ServiceManager
        // throws the “no instance returned” error you are seeing.
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
