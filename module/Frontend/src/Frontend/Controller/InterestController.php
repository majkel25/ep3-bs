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

            // Do NOT start a new session with default name; assume ep3-bs already did it.
            // But if for some reason it's not started yet, start it without changing the name.
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }

            $serviceManager     = $this->getServiceLocator();
            $userSessionManager = $serviceManager->get('User\Manager\UserSessionManager');
            $user               = $userSessionManager->getSessionUser();

            if (! $user) {
                // DEBUG: show what PHP thinks the session & cookies are
                return new JsonModel(array(
                    'ok'        => false,
                    'error'     => 'AUTH_REQUIRED',
                    'session'   => $_SESSION,
                    'cookies'   => $_COOKIE,
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
            // TEMPORARY: expose the exception so we can see what's going on
            return new JsonModel(array(
                'ok'      => false,
                'error'   => 'EXCEPTION',
                'message' => $e->getMessage(),
                'type'    => get_class($e),
            ));
        }
    }
}
