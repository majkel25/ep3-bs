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

        // --- make sure we are attached to the SAME session as ep3-bs uses ---
        $serviceManager = $this->getServiceLocator();
        $config         = $serviceManager->get('Config');

        $sessionName = null;
        if (isset($config['session']['config']['name'])
            && is_string($config['session']['config']['name'])
            && $config['session']['config']['name'] !== ''
        ) {
            $sessionName = $config['session']['config']['name'];
        }

        if ($sessionName !== null) {
            // If PHP is using a different session name, switch to the ep3 one
            if (session_name() !== $sessionName) {
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_write_close();
                }
                session_name($sessionName);
            }
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        // --- end session attach ---

        // Get logged-in user via UserSessionManager (standard ep3-bs way)
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
