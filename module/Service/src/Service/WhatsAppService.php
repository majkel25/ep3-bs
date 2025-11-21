<?php
namespace Service\Service;

use Zend\Http\Client;
use Zend\Http\Request;

/**
 * Simple Twilio WhatsApp / SMS sender.
 *
 * Reads configuration from $config['whatsapp'] in local.php:
 *  - enabled
 *  - account_sid
 *  - auth_token
 *  - messaging_service_sid (preferred)
 *  - from (fallback)
 *  - timeout
 */
class WhatsAppService
{
    /**
     * @var Client
     */
    protected $client;

    /**
     * @var array
     */
    protected $cfg;

    /**
     * @param Client $client
     * @param array  $config
     */
    public function __construct(Client $client, array $config)
    {
        $this->client = $client;
        $this->cfg    = isset($config['whatsapp']) ? $config['whatsapp'] : array();
    }

    /**
     * @return bool
     */
    public function isEnabled()
    {
        return !empty($this->cfg['enabled'])
            && !empty($this->cfg['account_sid'])
            && !empty($this->cfg['auth_token']);
    }

    /**
     * Send a WhatsApp or SMS message to a single number via Twilio.
     *
     * For WhatsApp, $toNumber must be in the format "whatsapp:+441234567890".
     *
     * @param string $toNumber
     * @param string $body
     */
    public function sendToNumber($toNumber, $body)
    {
        if (!$this->isEnabled()) {
            return;
        }

        $toNumber = trim((string)$toNumber);
        if ($toNumber === '') {
            return;
        }

        $payload = $this->buildTwilioPayload($toNumber, $body);
        $this->sendTwilioRequest($payload);
    }

    /**
     * @param string $toNumber
     * @param string $body
     * @return array
     */
    protected function buildTwilioPayload($toNumber, $body)
    {
        $data = array(
            'To'   => $toNumber,
            'Body' => $body,
        );

        if (!empty($this->cfg['messaging_service_sid'])) {
            $data['MessagingServiceSid'] = $this->cfg['messaging_service_sid'];
        } elseif (!empty($this->cfg['from'])) {
            $data['From'] = $this->cfg['from'];
        }

        return $data;
    }

    /**
     * @param array $payload
     */
    protected function sendTwilioRequest(array $payload)
    {
        $accountSid = $this->cfg['account_sid'];
        $authToken  = $this->cfg['auth_token'];

        $url = sprintf(
            'https://api.twilio.com/2010-04-01/Accounts/%s/Messages.json',
            rawurlencode($accountSid)
        );

        $this->client->reset();
        $this->client->setUri($url);
        $this->client->setMethod(Request::METHOD_POST);
        $this->client->setAuth($accountSid, $authToken);
        $this->client->setParameterPost($payload);
        $this->client->setOptions(array(
            'timeout' => isset($this->cfg['timeout']) ? (int)$this->cfg['timeout'] : 5,
        ));

        try {
            $this->client->send();
        } catch (\Exception $e) {
            // Optional: log the error
        }
    }
}
