<?php

namespace Frontend\Controller;

use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\JsonModel;

use Zend\Session\Container;

use Service\Service\BookingInterestService;
use Service\Service\WhatsAppService;

use Zend\Mail\Transport\Sendmail;
use Zend\Mail\Transport\Smtp;
use Zend\Mail\Transport\SmtpOptions;

class InterestController extends AbstractActionController
{
    /**
     * AJAX endpoint: register “notify me if a slot opens” for a given day
     */
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

            $serviceManager = $this->getServiceLocator();
            $config         = $serviceManager->get('Config');

            // ------------------------------------------------------------------
            // SESSION + CURRENT USER (WITHOUT UserSessionManager SERVICE)
            // ------------------------------------------------------------------

            // Make sure PHP session is started (usually already done by ep3-bs)
            if (session_status() !== PHP_SESSION_ACTIVE) {
                @session_start();
            }

            // The ep3-bs user session is stored in the "UserSession" container
            $session = new Container('UserSession');

            if (!isset($session->uid) || !is_numeric($session->uid) || $session->uid <= 0) {
                return new JsonModel(array(
                    'ok'    => false,
                    'error' => 'AUTH_REQUIRED',
                ));
            }

            $userId = (int)$session->uid;

            // (Optional but safer) – verify that this user actually exists
            $userManager = $serviceManager->get('User\Manager\UserManager');
            $user        = $userManager->get($userId, false);

            if (! $user) {
                return new JsonModel(array(
                    'ok'    => false,
                    'error' => 'AUTH_REQUIRED',
                ));
            }

            // ------------------------------------------------------------------
            // VALIDATE DATE
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

            // ------------------------------------------------------------------
            // BUILD BookingInterestService MANUALLY
            // ------------------------------------------------------------------

            // DB adapter
            $dbAdapter = $serviceManager->get('Zend\Db\Adapter\Adapter');

            // Mail configuration
            $mailCfg = isset($config['mail']) ? $config['mail'] : array();

            // Decide which mail transport to use
            $type = isset($mailCfg['type']) ? strtolower($mailCfg['type']) : 'sendmail';

            if ($type === 'smtp' || $type === 'smtp-tls') {

                $host = isset($mailCfg['host']) ? $mailCfg['host'] : 'localhost';
                $port = isset($mailCfg['port']) ? (int)$mailCfg['port'] : 25;

                $optionsArray = array(
                    'name' => $host,
                    'host' => $host,
                    'port' => $port,
                );

                if (!empty($mailCfg['user'])) {
                    $optionsArray['connection_class'] =
                        !empty($mailCfg['auth']) ? $mailCfg['auth'] : 'login';

                    $connCfg = array(
                        'username' => $mailCfg['user'],
                        'password' => isset($mailCfg['pw']) ? $mailCfg['pw'] : '',
                    );

                    if ($type === 'smtp-tls') {
                        $connCfg['ssl'] = 'tls';
                    }

                    $optionsArray['connection_config'] = $connCfg;
                }

                $options       = new SmtpOptions($optionsArray);
                $mailTransport = new Smtp($options);

            } else {
                // Default: Sendmail
                $mailTransport = new Sendmail();
            }

            // WhatsApp service is optional
            $whatsApp = null;
            if ($serviceManager->has(WhatsAppService::class)) {
                try {
                    $whatsApp = $serviceManager->get(WhatsAppService::class);
                } catch (\Exception $e) {
                    $whatsApp = null;
                }
            }

            // Create BookingInterestService directly
            $bookingInterestService = new BookingInterestService(
                $dbAdapter,
                $mailTransport,
                $mailCfg,
                $whatsApp
            );

            // ------------------------------------------------------------------
            // REGISTER INTEREST
            // ------------------------------------------------------------------

            $bookingInterestService->registerInterest($userId, $date);

            return new JsonModel(array(
                'ok' => true,
            ));

        } catch (\Exception $e) {
            // Last-resort error info (still JSON)
            return new JsonModel(array(
                'ok'      => false,
                'error'   => 'EXCEPTION',
                'message' => $e->getMessage(),
                '_debug_controller' => 'InterestController v3',
            ));
        }
    }
}
