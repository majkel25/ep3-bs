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

        // Get service manager
        $serviceManager = $this->getServiceLocator();

        // *** IMPORTANT: explicitly start the Zend session ***
        // This makes sure the session behind the ep3-bs-session cookie is loaded.
        $sessionManager = $serviceManager->get('Zend\Session\SessionManager');
        $sessionManager->start();

        // Get logged-in user via UserSessionManager (the way ep3-bs normally does it)
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

        try {
            $svc->registerInterest($userId, $date);
        } catch (\Exception $e) {
            // If something goes wrong in the service, respond with a generic error
            return new JsonModel(array(
                'ok'    => false,
                'error' => 'SERVER_ERROR',
            ));
        }

        return new JsonModel(array(
            'ok' => true,
        ));
    }
}
