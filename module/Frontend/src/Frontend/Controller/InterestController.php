<?php

namespace Frontend\Controller;

use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\JsonModel;

use Service\Service\BookingInterestService;

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
        $request = $this->getRequest();

        if (! $request->isPost()) {
            return new JsonModel(array(
                'ok'    => false,
                'error' => 'METHOD_NOT_ALLOWED',
            ));
        }

        $serviceManager = $this->getServiceLocator();

        // --------------------------------------------------------------------
        // Get logged-in user via UserSessionManager
        // --------------------------------------------------------------------
        try {
            /** @var \User\Manager\UserSessionManager $userSessionManager */
            $userSessionManager = $serviceManager->get('User\Manager\UserSessionManager');
        } catch (\Exception $e) {
            return new JsonModel(array(
                'ok'      => false,
                'error'   => 'EXCEPTION',
                'message' => 'Could not create User\\Manager\\UserSessionManager: ' . $e->getMessage(),
            ));
        }

        $user = $userSessionManager->getSessionUser();

        if (! $user) {
            return new JsonModel(array(
                'ok'    => false,
                'error' => 'AUTH_REQUIRED',
            ));
        }

        // --------------------------------------------------------------------
        // Validate date parameter
        // --------------------------------------------------------------------
        $dateStr = $this->params()->fromPost('date');

        if (! $dateStr || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) {
            return new JsonModel(array(
                'ok'    => false,
                'error' => 'INVALID_DATE',
            ));
        }

        try {
            $date = new \DateTime($dateStr);
        } catch (\Exception $e) {
            return new JsonModel(array(
                'ok'    => false,
                'error' => 'INVALID_DATE',
            ));
        }

        // ep3-bs user entities usually store primary key as "uid"
        $userId = (int) $user->need('uid');

        // --------------------------------------------------------------------
        // Get BookingInterestService via ServiceManager
        // --------------------------------------------------------------------
        try {
            /** @var BookingInterestService $bookingInterestService */
            $bookingInterestService = $serviceManager->get(BookingInterestService::class);
        } catch (\Exception $e) {
            return new JsonModel(array(
                'ok'      => false,
                'error'   => 'EXCEPTION',
                'message' => 'Could not create Service\\Service\\BookingInterestService: ' . $e->getMessage(),
            ));
        }

        // --------------------------------------------------------------------
        // Register the interest
        // --------------------------------------------------------------------
        try {
            $bookingInterestService->registerInterest($userId, $date);
        } catch (\Exception $e) {
            return new JsonModel(array(
                'ok'      => false,
                'error'   => 'SERVER_ERROR',
                'message' => $e->getMessage(),
            ));
        }

        return new JsonModel(array(
            'ok' => true,
        ));
    }
}
