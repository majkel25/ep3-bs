<?php

namespace Frontend\Controller;

use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\JsonModel;

use Zend\Db\Adapter\Adapter;
use Zend\Mail\Transport\Sendmail;
use Zend\Mail\Transport\Smtp;
use Zend\Mail\Transport\SmtpOptions;
use Zend\Session\Container;

use Service\Service\BookingInterestService;
use Service\Service\WhatsAppService;

class InterestController extends AbstractActionController
{
    /**
     * AJAX endpoint: register interest in a specific date.
     *
     * Expects POST:
     *   - date: YYYY-MM-DD
     *
     * Returns JSON:
     *   { "ok": true }
     * or
     *   { "ok": false, "error": "..." }
     */
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

        // --------------------------------------------------------------------
        // 1) Attach to the SAME PHP session as ep3-bs (using ConfigManager)
        // --------------------------------------------------------------------
        try {
            /** @var \Base\Manager\ConfigManager $configManager */
            $configManager = $serviceManager->get('Base\Manager\ConfigManager');
        } catch (\Exception $e) {
            return new JsonModel(array(
                'ok'      => false,
                'error'   => 'EXCEPTION',
                'message' => 'Could not get Base\\Manager\\ConfigManager: ' . $e->getMessage(),
            ));
        }

        try {
            $sessionName = $configManager->need('session_config.name');
        } catch (\Exception $e) {
            return new JsonModel(array(
                'ok'      => false,
                'error'   => 'EXCEPTION',
                'message' => 'Could not read session_config.name: ' . $e->getMessage(),
            ));
        }

        if (is_string($sessionName) && $sessionName !== '') {
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

        // --------------------------------------------------------------------
        // 2) Resolve current user directly from the session container
        //    (same logic as UserSessionManager::getSessionUser())
        // --------------------------------------------------------------------
        try {
            $userManager = $serviceManager->get('User\Manager\UserManager');
        } catch (\Exception $e) {
            return new JsonModel(array(
                'ok'      => false,
                'error'   => 'EXCEPTION',
                'message' => 'Could not get User\\Manager\\UserManager: ' . $e->getMessage(),
            ));
        }

        $sessionContainer = new Container('UserSession');

        if (!isset($sessionContainer->uid) || !is_numeric($sessionContainer->uid) || $sessionContainer->uid <= 0) {
            return new JsonModel(array(
                'ok'    => false,
                'error' => 'AUTH_REQUIRED',
            ));
        }

        try {
            $user = $userManager->get($sessionContainer->uid, false);
        } catch (\Exception $e) {
            return new JsonModel(array(
                'ok'      => false,
                'error'   => 'EXCEPTION',
                'message' => 'Could not load user from UserManager: ' . $e->getMessage(),
            ));
        }

        if (! $user) {
            return new JsonModel(array(
                'ok'    => false,
                'error' => 'AUTH_REQUIRED',
            ));
        }

        $userId = (int) $user->need('uid');

        // --------------------------------------------------------------------
        // 3) Validate date parameter
        // --------------------------------------------------------------------
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

        // --------------------------------------------------------------------
        // 4) Build BookingInterestService **manually** – no ServiceManager->get
        // --------------------------------------------------------------------

        // 4a) DB adapter
        try {
            /** @var Adapter $dbAdapter */
            $dbAdapter = $serviceManager->get('Zend\Db\Adapter\Adapter');
        } catch (\Exception $e) {
            return new JsonModel(array(
                'ok'      => false,
                'error'   => 'EXCEPTION',
                'message' => 'Could not get Zend\\Db\\Adapter\\Adapter: ' . $e->getMessage(),
            ));
        }

        // 4b) Mail configuration
        try {
            $config = $serviceManager->get('Config');
        } catch (\Exception $e) {
            $config = array();
        }

        $mailCfg = isset($config['mail']) && is_array($config['mail'])
            ? $config['mail']
            : array();

        // 4c) Build mail transport (Sendmail / SMTP / SMTP-TLS)
        $type = isset($mailCfg['type']) ? strtolower($mailCfg['type']) : 'sendmail';

        if ($type === 'smtp' || $type === 'smtp-tls') {
            $host = isset($mailCfg['host']) ? $mailCfg['host'] : 'localhost';
            $port = isset($mailCfg['port']) ? (int) $mailCfg['port'] : 25;

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

        // 4d) Optional WhatsApp service – best-effort only
        $whatsApp = null;

        if ($serviceManager->has(WhatsAppService::class)) {
            try {
                $whatsApp = $serviceManager->get(WhatsAppService::class);
            } catch (\Exception $e) {
                $whatsApp = null;
            }
        }

        // 4e) Finally create BookingInterestService directly
        $bookingInterestService = new BookingInterestService(
            $dbAdapter,
            $mailTransport,
            $mailCfg,
            $whatsApp
        );

        // --------------------------------------------------------------------
        // 5) Register the interest
        // --------------------------------------------------------------------
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
