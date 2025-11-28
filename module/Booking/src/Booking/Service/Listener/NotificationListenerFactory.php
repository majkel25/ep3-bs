<?php

namespace Booking\Service\Listener;

use Backend\Service\MailService as BackendMailService;
use Base\Manager\OptionManager;
use Base\View\Helper\DateRange;
use Base\View\Helper\PriceFormatPlain;
use Booking\Manager\Booking\BillManager;
use Booking\Manager\ReservationManager;
use Square\Manager\SquareManager;
use User\Manager\UserManager;
use User\Service\MailService as UserMailService;
use Zend\I18n\View\Helper\DateFormat;
use Zend\Mvc\I18n\Translator;
use Zend\ServiceManager\FactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;

class NotificationListenerFactory implements FactoryInterface
{
    /**
     * Create NotificationListener service.
     *
     * @param ServiceLocatorInterface|null $serviceLocator
     * @return NotificationListener
     */
    public function createService(ServiceLocatorInterface $serviceLocator = null)
    {
        if ($serviceLocator && method_exists($serviceLocator, 'getServiceLocator')) {
            $root = $serviceLocator->getServiceLocator();
            if ($root) {
                $serviceLocator = $root;
            }
        }

        if ($serviceLocator === null) {
            error_log('NotificationListenerFactory: serviceLocator is NULL, proceeding with minimal wiring.');
        }

        /** @var OptionManager $optionManager */
        $optionManager = $serviceLocator
            ? $serviceLocator->get(OptionManager::class)
            : null;

        /** @var ReservationManager $reservationManager */
        $reservationManager = $serviceLocator
            ? $serviceLocator->get(ReservationManager::class)
            : null;

        /** @var SquareManager $squareManager */
        $squareManager = $serviceLocator
            ? $serviceLocator->get(SquareManager::class)
            : null;

        /** @var UserManager $userManager */
        $userManager = $serviceLocator
            ? $serviceLocator->get(UserManager::class)
            : null;

        /** @var UserMailService $userMailService */
        $userMailService = $serviceLocator
            ? $serviceLocator->get(UserMailService::class)
            : null;

        /** @var BackendMailService $backendMailService */
        $backendMailService = $serviceLocator
            ? $serviceLocator->get(BackendMailService::class)
            : null;

        // View helpers
        $viewHelperManager = $serviceLocator
            ? $serviceLocator->get('ViewHelperManager')
            : null;

        /** @var DateFormat $dateFormatHelper */
        $dateFormatHelper = $viewHelperManager
            ? $viewHelperManager->get('dateFormat')
            : null;

        /** @var DateRange $dateRangeHelper */
        $dateRangeHelper = $viewHelperManager
            ? $viewHelperManager->get('dateRange')
            : null;

        /** @var PriceFormatPlain $priceFormatHelper */
        $priceFormatHelper = $viewHelperManager
            ? $viewHelperManager->get('priceFormatPlain')
            : null;

        /** @var Translator $translator */
        $translator = $serviceLocator
            ? $serviceLocator->get('MvcTranslator')
            : null;

        /** @var BillManager $bookingBillManager */
        $bookingBillManager = $serviceLocator
            ? $serviceLocator->get(BillManager::class)
            : null;

        // DB adapter (for interest notifications)
        $dbAdapter = $serviceLocator
            ? $serviceLocator->get('Zend\Db\Adapter\Adapter')
            : null;

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
            $dbAdapter
        );
    }
}
