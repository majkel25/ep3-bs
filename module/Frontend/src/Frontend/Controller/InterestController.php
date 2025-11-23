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

        $serviceManager = $this->getServiceLocator();

        // Logged-in user via UserSessionManager (no manual session_start)
        try {
            $userSessionManager = $serviceManager->get('User\Manager\UserSessionManager');
            $user               = $userSessionManager->getSessionUser();
        } catch (\Exception $e) {
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

        $userId = (int) $user->need('uid');

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
