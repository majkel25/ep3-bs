<?php

namespace Frontend\Controller;

use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\JsonModel;

use Service\Service\BookingInterestService;
use Service\Service\WhatsAppService;

use Zend\Mail\Transport\Sendmail;
use Zend\Mail\Transport\Smtp;
use Zend\Mail\Transport\SmtpOptions;

class InterestController extends AbstractActionController
{
    /**
     * DEBUG WRAPPER:
     * Catches any exception and forces a JSON response instead of a 500 HTML error page.
     */
    public function registerAction()
    {
        try {
            // Small debug flag so we can see that THIS version is live
            $result = $this->doRegister();
            if ($result instanceof JsonModel) {
                $data = $result->getVariables();
                $data['_debug_controller'] = 'InterestController v2';
                return new JsonModel($data);
            }

            // Should not normally reach here, but just in case:
            return new JsonModel(array(
                'ok'    => false,
                'error' => 'UNEXPECTED_RESULT',
                '_debug_controller' => 'InterestController v2',
            ));

        } catch (\Throwable $e) {
            // PHP 7/8 path
            return new JsonModel(array(
                'ok'      => false,
                'error'   => 'EXCEPTION',
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
                '_debug_controller' => 'InterestController v2',
            ));
        } catch (\Exception $e) {
            // For older PHP if \Throwable wasn’t available (safety)
            return new JsonModel(array(
                'ok'      => false,
                'error'   => 'EXCEPTION',
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
                '_debug_controller' => 'InterestController v2',
            ));
        }
    }

    /**
     * Original logic moved here. Any exception thrown in here will be caught
     * by registerAction() and returned as JSON instead of a 500 page.
     */
    private function doRegister()
    {
        $request = $this->getRequest();

        if (! $request->isPost()) {
            return new JsonModel(array(
                'ok'    => false,
                'error' => 'METHOD_NOT_ALLOWED',
            ));
        }

        // ------------------------------------------------------------------
        // Attach to SAME PHP session as ep3-bs
        // ------------------------------------------------------------------
        $serviceManager = $this->getServiceLocator();
        $config         = $serviceManager->get('Config');

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

        // ------------------------------------------------------------------
        // Manually build BookingInterestService (NO ServiceManager get())
        // ------------------------------------------------------------------

        // DB adapter
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

        // Create the service DIRECTLY (no ServiceManager call)
        $bookingInterestService = new BookingInterestService(
            $dbAdapter,
            $mailTransport,
            $mailCfg,
            $whatsApp
        );

        // ------------------------------------------------------------------
        // Register the interest
        // ------------------------------------------------------------------
        $bookingInterestService->registerInterest($userId, $date);

        return new JsonModel(array(
            'ok' => true,
        ));
    }
}
