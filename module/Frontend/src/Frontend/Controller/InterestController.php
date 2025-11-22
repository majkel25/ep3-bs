<?php

namespace Frontend\Controller;

use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\JsonModel;
use Service\Service\BookingInterestService;

class InterestController extends AbstractActionController
{
    public function registerAction()
    {
        try {
            $request = $this->getRequest();

            if (! $request->isPost()) {
                return new JsonModel(array(
                    'ok'    => false,
                    'error' => 'METHOD_NOT_ALLOWED',
                ));
            }

            // Make sure the PHP session behind ep3-bs-session cookie is started
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }

            // Get logged-in user via UserSessionManager (the way ep3-bs normally does it)
            $serviceManager     = $this->getServiceLocator();
            $userSessionManager = $serviceManager->get('User\Manager\UserSessionManager');
            $user               = $userSessionManager->getSessionUser();

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

            // ep3-bs user entities normally store primary key as "uid"
            $userId = (int) $user->need('uid');

            /** @var BookingInterestService $svc */
            $svc = $serviceManager->get(BookingInterestService::class);

            $svc->registerInterest($userId, $date);

            return new JsonModel(array(
                'ok' => true,
            ));
        } catch (\Throwable $e) {
            // TEMPORARY DEBUG OUTPUT – will make HTTP 200 with JSON error instead of 500
            return new JsonModel(array(
                'ok'      => false,
                'error'   => 'EXCEPTION',
                'message' => $e->getMessage(),
                'type'    => get_class($e),
            ));
        }
    }
}
