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
use Zend\I18n\View\Helper\DateFormat;
use Zend\ServiceManager\FactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;

class NotificationListenerFactory implements FactoryInterface
{
    /**
     * ZF2 style factory.
     *
     * @param ServiceLocatorInterface $serviceLocator
     * @return NotificationListener
     */
    public function createService(ServiceLocatorInterface $serviceLocator)
    {
        // In some setups $serviceLocator is a plugin manager – unwrap to real container
        if (method_exists($serviceLocator, 'getServiceLocator')) {
            $container = $serviceLocator->getServiceLocator();
        } else {
            $container = $serviceLocator;
        }

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

        // View helpers
        $viewHelperManager = $container->get('ViewHelperManager');

        /** @var DateFormat $dateFormatHelper */
        $dateFormatHelper = $viewHelperManager->get('dateFormat');

        /** @var DateRange $dateRangeHelper */
        $dateRangeHelper = $viewHelperManager->get('dateRange');

        /** @var PriceFormatPlain|null $priceFormatHelper */
        $priceFormatHelper = null;
        if ($viewHelperManager->has('priceFormatPlain')) {
            $priceFormatHelper = $viewHelperManager->get('priceFormatPlain');
        }

        /** @var TranslatorInterface $translator */
        // In ep3 this is normally registered as "MvcTranslator"
        $translator = $container->get('MvcTranslator');

        // Optional: service that handles “register interest in a day” notifications
        /** @var BookingInterestService|null $bookingInterestService */
        $bookingInterestService = null;
        if ($container->has(\Service\Service\BookingInterestService::class)) {
            $bookingInterestService = $container->get(\Service\Service\BookingInterestService::class);
        }

        // Optional: BillManager (only used if you later want to include billing
        // details in notifications – but safe to inject here)
        /** @var BillManager|null $bookingBillManager */
        $bookingBillManager = null;
        if ($container->has('Booking\Manager\Booking\BillManager')) {
            $bookingBillManager = $container->get('Booking\Manager\Booking\BillManager');
        }

        // Construct the listener (constructor signature matches your current
        // NotificationListener.php file)
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

    /**
     * ZF3 style factory proxy – just forwards to createService().
     *
     * @param ContainerInterface $container
     * @param string             $requestedName
     * @param null|array         $options
     * @return NotificationListener
     */
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        return $this->createService($container);
    }
}
