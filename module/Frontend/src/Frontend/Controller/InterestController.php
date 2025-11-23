<?php

namespace Frontend\Controller;

use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\JsonModel;

use Service\Service\BookingInterestService;
use Service\Service\WhatsAppService;

use Zend\Mail\Transport\Sendmail;
use Zend\Mail\Transport\Smtp;
use Zend\Mail\Transport\SmtpOptions;

/**
 * AJAX endpoint to register interest in a given day.
 *
 * URL:  /interest/register
 * POST: date=YYYY-MM-DD
 *
 * Response (JSON):
 *   { "ok": true }
 * or
 *   { "ok": false, "error": "...", "message": "..." (optional) }
 */
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

        $serviceManager = $this->getServiceLocator();

        // ------------------------------------------------------------------
        // Make sure we are on the same PHP session as ep3-bs
        // ------------------------------------------------------------------
        $config = $serviceManager->get('Config');

        if (isset($config['session']['config']['name'])
            && is_string($config['session']['config']['name'])
            && $config['session']['config']['name'] !== ''
        ) {
            $sessionName = $config['session']['config']['name'];

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

        // ------------------------------------------------------------------
        // Logged-in user via UserSessionManager
        // ------------------------------------------------------------------
        try {
            /** @var \User\Manager\UserSessionManager $userSessionManager */
            $userSessionManager = $serviceManager->get('User\Manager\UserSessionManager');
        } catch (\Exception $e) {
            return new JsonModel(array(
                'ok'      => false,
                'error'   => 'EXCEPTION',
                'message' => 'Could not create User\\Manager\\UserSessionManager: ' . $e->getMessage(),
            ));
        }

        $user = $userSessionManager->getSessionUser();

        if (! $user) {
            return new JsonModel(array(
                'ok'    => false,
                'error' => 'AUTH_REQUIRED',
            ));
        }

        // ------------------------------------------------------------------
        // Validate date parameter
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

        // ep3-bs user entities usually store primary key as "uid"
        $userId = (int) $user->need('uid');

        // ------------------------------------------------------------------
        // MANUALLY build BookingInterestService (NO ServiceManager::get())
        // ------------------------------------------------------------------

        // DB adapter
        $dbAdapter = $serviceManager->get('Zend\Db\Adapter\Adapter');

        // Mail configuration
        $mailCfg = isset($config['mail']) ? $config['mail'] : array();

        // Build mail transport (Sendmail / SMTP / SMTP-TLS)
        $type = isset($mailCfg['type']) ? strtolower($mailCfg['type']) : 'sendmail';

        if ($type === 'smtp' || $type === 'smtp-tls') {

            $host = isset($mailCfg['host']) ? $mailCfg['host'] : 'localhost';
            $port = isset($mailCfg['port']) ? (int) $mailCfg['port'] : 25;

            $optionsArray = array(
                'name' => $host,
                'host' => $host,
                'port' => $port,
            );

            // Authentication config
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

        // WhatsApp service is optional – if anything fails, we just skip WhatsApp.
        $whatsApp = null;
        if ($serviceManager->has(WhatsAppService::class)) {
            try {
                $whatsApp = $serviceManager->get(WhatsAppService::class);
            } catch (\Exception $e) {
                $whatsApp = null;
            }
        }

        // Finally: create BookingInterestService DIRECTLY
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
                'ok'      => false,
                'error'   => 'SERVER_ERROR',
                'message' => $e->getMessage(),
            ));
        }

        return new JsonModel(array(
            'ok' => true,
        ));
    }
}
