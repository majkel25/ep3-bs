<?php

namespace Frontend\Controller;

use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\JsonModel;
use Zend\Db\Adapter\Adapter;
use Zend\Mail\Transport\Sendmail;
use Zend\Mail\Transport\Smtp;
use Zend\Mail\Transport\SmtpOptions;

use User\Manager\UserSessionManager;
use Service\Service\WhatsAppService;

class InterestController extends AbstractActionController
{
    /**
     * AJAX endpoint: register interest in a specific date.
     * POST:
     *     date = YYYY-MM-DD
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

            /**
             * 1) Resolve UserSessionManager
             */
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

            /**
             * 2) Get current session user
             */
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

            /**
             * 3) Extract user ID
             *    (this is the logic that works – unchanged)
             */
            $userId = null;

            if (method_exists($user, 'need')) {
                // your User entity uses ->need('uid')
                $userId = $user->need('uid');
            } elseif (method_exists($user, 'get')) {
                $userId = $user->get('uid');
            }

            if (! $userId || ! is_numeric($userId)) {
                return new JsonModel([
                    'ok'    => false,
                    'error' => 'USER_ID_NOT_AVAILABLE',
                ]);
            }

            /**
             * 4) Validate date parameter from POST
             */
            $dateStr = $this->params()->fromPost('date');

            if (! $dateStr || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) {
                return new JsonModel([
                    'ok'    => false,
                    'error' => 'INVALID_DATE',
                ]);
            }

            try {
                $date = new \DateTime($dateStr);
            } catch (\Throwable $e) {
                return new JsonModel([
                    'ok'    => false,
                    'error' => 'INVALID_DATE',
                ]);
            }

            /**
             * 5) DB adapter
             */
            try {
                /** @var Adapter $dbAdapter */
                $dbAdapter = $serviceManager->get('Zend\Db\Adapter\Adapter');
            } catch (\Throwable $e) {
                return new JsonModel([
                    'ok'      => false,
                    'error'   => 'DB_ADAPTER_ERROR',
                    'message' => $e->getMessage(),
                    'type'    => get_class($e),
                ]);
            }

            /**
             * 6) Mail configuration / transport
             */
            $config  = $serviceManager->get('Config');
            $mailCfg = isset($config['mail']) ? $config['mail'] : [];

            $type = isset($mailCfg['type']) ? strtolower($mailCfg['type']) : 'sendmail';

            if ($type === 'smtp' || $type === 'smtp-tls') {
                $host = $mailCfg['host'] ?? 'localhost';
                $port = (int)($mailCfg['port'] ?? 25);

                $opt = [
                    'name' => $host,
                    'host' => $host,
                    'port' => $port,
                ];

                if (! empty($mailCfg['user'])) {
