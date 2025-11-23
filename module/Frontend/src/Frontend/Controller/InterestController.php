<?php

namespace Frontend\Controller;

use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\JsonModel;

use Service\Service\BookingInterestService;

class InterestController extends AbstractActionController
{
    public function registerAction()
    {
        $request = $this->getRequest();

        if (! $request->isPost()) {
            return new JsonModel(array(
                'ok'    => false,
                'error' => 'METHOD_NOT_ALLOWED',
            ));
        }

        // ------------------------------------------------------------------
        // Get logged-in user via UserSessionManager – NO manual session_start
        // ------------------------------------------------------------------
        $serviceManager = $this->getServiceLocator();

        try {
            $userSessionManager = $serviceManager->get('User\Manager\UserSessionManager');
            $user               = $userSessionManager->getSessionUser();
        } catch (\Exception $e) {
            // Any validator / session problem ends up here
            return new JsonModel(array(
                'ok'      => false,
                'error'   => 'AUTH_EXCEPTION',
                'message' => $e->getMessage(),
            ));
        }

        if (! $user) {
            return new JsonModel(array(
                'ok'    => false,
                'error' => 'AUTH_REQUIRED',
            ));
        }

        // ------------------------------------------------------------------
        // Validate date from POST
        // ------------------------------------------------------------------
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

        // ep3-bs users use "uid" as PK
        $userId = (int) $user->need('uid');

        // ------------------------------------------------------------------
        // Use BookingInterestService from ServiceManager
        // ------------------------------------------------------------------
        /** @var BookingInterestService $bookingInterestService */
        $bookingInterestService = $serviceManager->get(BookingInterestService::class);

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
