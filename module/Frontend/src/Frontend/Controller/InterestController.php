<?php

namespace Frontend\Controller;

use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\JsonModel;
use User\Manager\UserSessionManager;

class InterestController extends AbstractActionController
{
    /**
     * Temporary debug endpoint: just return info about the current session user.
     * POST only.
     */
    public function registerAction()
    {
        try {
            $request = $this->getRequest();

            if (! $request->isPost()) {
                return new JsonModel([
                    'ok'    => false,
                    'error' => 'METHOD_NOT_ALLOWED',
                ]);
            }

            $serviceManager = $this->getServiceLocator();

            // 1) Try to get the UserSessionManager service
            try {
                /** @var UserSessionManager $userSessionManager */
                $userSessionManager = $serviceManager->get('User\Manager\UserSessionManager');
            } catch (\Throwable $e) {
                return new JsonModel([
                    'ok'      => false,
                    'error'   => 'EXCEPTION_RESOLVING_USERSESSIONMANAGER',
                    'message' => $e->getMessage(),
                    'type'    => get_class($e),
                ]);
            }

            // 2) Try to get the current session user
            try {
                $user = $userSessionManager->getSessionUser();
            } catch (\Throwable $e) {
                return new JsonModel([
                    'ok'      => false,
                    'error'   => 'EXCEPTION_GETTING_SESSION_USER',
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

            // 3) Extract the user ID in the same way as UserSessionManager uses it
            $userId = null;

            if (method_exists($user, 'need')) {
                // this matches how UserSessionManager stores the uid
                $userId = $user->need('uid');
            } elseif (method_exists($user, 'get')) {
                $userId = $user->get('uid');
            }

            // 4) Build a small debug structure so we can see what we got
            $userInfo = [
                'class'  => get_class($user),
                'userId' => $userId,
            ];

            // If the entity has toArray(), include it for extra debug
            if (method_exists($user, 'toArray')) {
                $userInfo['data'] = $user->toArray();
            }

            return new JsonModel([
                'ok'          => true,
                'user_present'=> true,
                'user_info'   => $userInfo,
            ]);
        } catch (\Throwable $e) {
            // Final catch-all – if we hit this, at least it’s still JSON.
            return new JsonModel([
                'ok'    => false,
                'error' => 'UNCAUGHT_SERVER_EXCEPTION',
                'msg'   => $e->getMessage(),
                'type'  => get_class($e),
            ]);
        }
    }
}
