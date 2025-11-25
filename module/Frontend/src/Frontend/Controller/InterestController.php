<?php

namespace Frontend\Controller;

use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\JsonModel;

class InterestController extends AbstractActionController
{
    /**
     * AJAX endpoint: register interest in a specific date.
     *
     * For now this is a **minimal** version only to debug the 500 error.
     * It does NOT talk to the DB or send emails yet.
     */
    public function registerAction()
    {
        $request = $this->getRequest();

        // ------------------------------------------------------------------
        // 1) Only allow POST
        // ------------------------------------------------------------------
        if (! $request->isPost()) {
            return new JsonModel([
                'ok'    => false,
                'error' => 'METHOD_NOT_ALLOWED',
            ]);
        }

        // ------------------------------------------------------------------
        // 2) Try to resolve the current user
        // ------------------------------------------------------------------
        try {
            $serviceManager      = $this->getServiceLocator();
            $userSessionManager  = $serviceManager->get('User\Manager\UserSessionManager');
            $user                = $userSessionManager->getUser();
        } catch (\Throwable $e) {
            // If *anything* goes wrong here, we still return JSON so we can see it
            return new JsonModel([
                'ok'      => false,
                'error'   => 'EXCEPTION_RESOLVING_USER',
                'message' => $e->getMessage(),
                'type'    => get_class($e),
            ]);
        }

        if (! $user) {
            return new JsonModel([
                'ok'    => false,
                'error' => 'USER_NOT_LOGGED_IN',
            ]);
        }

        // ------------------------------------------------------------------
        // 3) SUCCESS – for now we only confirm that the action runs correctly
        // ------------------------------------------------------------------
        // If the User entity has getId(), this will show it; otherwise it will
        // just show "logged in".
        $userId = method_exists($user, 'getId') ? $user->getId() : null;

        return new JsonModel([
            'ok'        => true,
            'debug'     => 'registerAction reached',
            'userId'    => $userId,
        ]);
    }
}
