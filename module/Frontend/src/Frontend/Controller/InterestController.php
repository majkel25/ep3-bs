<?php

namespace Frontend\Controller;

use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\JsonModel;
use Zend\Db\Adapter\Adapter;
use Zend\Mail\Transport\Sendmail;
use Zend\Mail\Transport\Smtp;
use Zend\Mail\Transport\SmtpOptions;

use Service\Service\BookingInterestService;
use Service\Service\WhatsAppService;

// Hard-include service to guarantee autoloading
require_once __DIR__ . '/../../../../Service/src/Service/BookingInterestService.php';

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

            //
            // 1) GET CURRENT LOGGED USER
            //
            try {
                /** @var \User\Manager\UserSessionManager $userSessionManager */
                $userSessionManager = $serviceManager->get('User\Manager\UserSessionManager');

                // this is the correct method from your UserSessionManager
                $user = $userSessionManager->getSessionUser();
            } catch (\Throwable $e) {
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

            //
            // 2) Extract user ID
            //    IMPORTANT: this now matches how your User entity is used elsewhere
            //
            $userId = null;

            if (method_exists($user, 'need')) {
                // same pattern as in UserSessionManager: $user->need('uid')
                $userId = $user->need('uid');
            } elseif (method_exists($user, 'get')) {
                $userId = $user->get('uid');
            } elseif (isset($user->id)) {
                $userId = $user->id;
            }

            if (! $userId || ! is_numeric($userId)) {
                return new JsonModel([
                    'ok'    => false,
                    'error' => 'USER_ID_NOT_AVAILABLE',
                ]);
            }

            //
            // 3) Validate date parameter
            //
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

            //
            // 4) Build DB adapter
            //
            try {
                /** @var Adapter $dbAdapter */
                $dbAdapter = $serviceManager->get('Zend\Db\Adapter\Adapter');
            } catch (\Throwable $e) {
                return new JsonModel([
                    'ok'      => false,
                    'error'   => 'DB_ADAPTER_ERROR',
                    'message' => $e->getMessage(),
                ]);
            }

            //
            // 5) Mail configuration
            //
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
                    $opt['connection_class'] = $mailCfg['auth'] ?? 'login';

                    $opt['connection_config'] = [
                        'username' => $mailCfg['user'],
                        'password' => $mailCfg['pw'] ?? '',
                    ];

                    if ($type === 'smtp-tls') {
                        $opt['connection_config']['ssl'] = 'tls';
                    }
                }

                $mailTransport = new Smtp(new SmtpOptions($opt));
            } else {
                $mailTransport = new Sendmail();
            }

            //
            // 6) WhatsApp optional
            //
            $whatsApp = null;

            if ($serviceManager->has(WhatsAppService::class)) {
                try {
                    $whatsApp = $serviceManager->get(WhatsAppService::class);
                } catch (\Throwable $e) {
                    $whatsApp = null;
                }
            }

            //
            // 7) Build the service manually 
            //
            $bookingInterestService = new BookingInterestService(
                $dbAdapter,
                $mailTransport,
                $mailCfg,
                $whatsApp
            );

            //
            // 8) Register interest
            //
            $bookingInterestService->registerInterest($userId, $date);

            return new JsonModel([
                'ok'      => true,
                'user_id' => (int)$userId,
                'date'    => $date->format('Y-m-d'),
            ]);
        } catch (\Throwable $e) {
            // FINAL fallback
            return new JsonModel([
                'ok'    => false,
                'error' => 'UNCAUGHT_SERVER_EXCEPTION',
                'msg'   => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'type'  => get_class($e),
            ]);
        }
    }
}
