<?php

namespace Frontend\Controller;

use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\JsonModel;

use Zend\Db\Adapter\Adapter;
use Zend\Mail\Transport\Sendmail;
use Zend\Mail\Transport\Smtp;
use Zend\Mail\Transport\SmtpOptions;
use Zend\Session\Container;

use Service\Service\BookingInterestService;
use Service\Service\WhatsAppService;

// Hard-include the service as a safety net in case autoload is still misbehaving
require_once __DIR__ . '/../../../../Service/src/Service/BookingInterestService.php';

class InterestController extends AbstractActionController
{
    /**
     * AJAX endpoint: register interest in a specific date.
     *
     * Expects POST:
     *   - date: YYYY-MM-DD
     *
     * Returns JSON:
     *   { "ok": true }
     * or
     *   { "ok": false, "error": "..." }
     */
    public function registerAction()
    {
        try {
            $request = $this->getRequest();

            if (!$request->isPost()) {
                return new JsonModel([
                    'ok'    => false,
                    'error' => 'METHOD_NOT_ALLOWED',
                ]);
            }

            // -----------------------------------------------------------------
            // 1) Resolve current user
            // -----------------------------------------------------------------
            $serviceManager     = $this->getServiceLocator();
            /** @var \User\Manager\UserSessionManager $userSessionManager */
            $userSessionManager = $serviceManager->get('User\Manager\UserSessionManager');
            $user               = $userSessionManager->getUser();

            if (!$user) {
                return new JsonModel([
                    'ok'    => false,
                    'error' => 'USER_NOT_LOGGED_IN',
                ]);
            }

            // -----------------------------------------------------------------
            // 2) Validate date parameter
            // -----------------------------------------------------------------
            $dateStr = $this->params()->fromPost('date');

            if (!$dateStr || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) {
                return new JsonModel([
                    'ok'    => false,
                    'error' => 'INVALID_DATE',
                ]);
            }

            try {
                $date = new \DateTime($dateStr);
            } catch (\Exception $e) {
                return new JsonModel([
                    'ok'    => false,
                    'error' => 'INVALID_DATE',
                ]);
            }

            // -----------------------------------------------------------------
            // 3) Get BookingInterestService from ServiceManager
            // -----------------------------------------------------------------
            /** @var BookingInterestService $bookingInterestService */
            $bookingInterestService = $serviceManager->get('Booking\Service\BookingInterestService');

            // NOTE: we pass $user and $date; if the signature is different
            // we’ll see the exact message in the JSON error below.
            $bookingInterestService->registerInterest($user, $date);

            // -----------------------------------------------------------------
            // 4) Success
            // -----------------------------------------------------------------
            return new JsonModel([
                'ok' => true,
            ]);

        } catch (\Throwable $e) {
            // DEBUG OUTPUT so we see the real cause instead of generic HTTP 500
            return new JsonModel([
                'ok'    => false,
                'error' => $e->getMessage(),
                'type'  => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
