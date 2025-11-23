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
    public function registerAction()
    {
        $request = $this->getRequest();

        if (! $request->isPost()) {
            return new JsonModel(array(
                'ok'    => false,
                'error' => 'METHOD_NOT_ALLOWED',
            ));
        }

        // ------------------------------------------------------------------
        // Get ServiceManager
        // ------------------------------------------------------------------
        $serviceManager = $this->getServiceLocator();
        $config         = $serviceManager->get('Config');

        // ------------------------------------------------------------------
        // Read logged-in user from Zend\Session\Container directly
        // ------------------------------------------------------------------
        try {
            /** @var \Zend\Session\SessionManager $sessionManager */
            $sessionManager   = $serviceManager->get('Zend\Session\SessionManager');
            $sessionContainer = new Container('UserSession', $sessionManager);
        } catch (\Exception $e) {
            return new JsonModel(array(
                'ok'      => false,
                'error'   => 'AUTH_EXCEPTION',
                'message' => $e->getMessage(),
            ));
        }

        if (!isset($sessionContainer->uid) || !is_numeric($sessionContainer->uid) || $sessionContainer->uid <= 0) {
            // No logged-in user in the standard ep3-bs session container
            return new JsonModel(array(
                'ok'    => false,
                'error' => 'AUTH_REQUIRED',
            ));
        }

        $userId = (int)$sessionContainer->uid;

        // ------------------------------------------------------------------
        // Read and validate date from POST
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
        // Build BookingInterestService (DB + mail + optional WhatsApp)
        // ------------------------------------------------------------------

        // DB adapter used by the rest of ep3-bs
        $dbAdapter = $serviceManager->get('Zend\Db\Adapter\Adapter');

        // Mail configuration
        $mailCfg = isset($config['mail']) ? $config['mail'] : array();

        // Build mail transport (Sendmail / SMTP / SMTP-TLS)
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

        // WhatsApp service is optional; if it fails, we just continue without it
        $whatsApp = null;
        if ($serviceManager->has(WhatsAppService::class)) {
            try {
                $whatsApp = $serviceManager->get(WhatsAppService::class);
            } catch (\Exception $e) {
                $whatsApp = null;
            }
        }

        // Create the service directly
        $bookingInterestService = new BookingInterestService(
            $dbAdapter,
            $mailTransport,
            $mailCfg,
            $whatsApp
        );

        // ------------------------------------------------------------------
        // Register the interest
        // ------------------------------------------------------------------
        try {
            $bookingInterestService->registerInterest($userId, $date);
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
