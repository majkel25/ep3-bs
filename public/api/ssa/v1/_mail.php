<?php
/**
 * ssaApiSendMail — send a plain-text email via the configured SMTP transport.
 *
 * Requires _auth0.php (which loads vendor/autoload.php) and _db.php
 * (which defines ssaApiLoadLocalConfig) to have been included before this file.
 *
 * @param string $to      Recipient email address
 * @param string $toName  Recipient display name
 * @param string $subject Email subject line
 * @param string $body    Plain-text email body
 *
 * @throws \Throwable on transport failure
 */
function ssaApiSendMail(string $to, string $toName, string $subject, string $body): void
{
    $config   = ssaApiLoadLocalConfig();
    $mailCfg  = $config['mail'] ?? [];

    $type     = strtolower((string)($mailCfg['type'] ?? 'smtp-tls'));
    $host     = (string)($mailCfg['host'] ?? 'localhost');
    $port     = (int)($mailCfg['port'] ?? 587);
    $user     = (string)($mailCfg['user'] ?? '');
    $pw       = (string)($mailCfg['pw'] ?? '');
    $auth     = (string)($mailCfg['auth'] ?? 'plain');
    $fromAddr = (string)($mailCfg['address'] ?? 'noreply@surrey-snooker-academy.co.uk');
    $fromName = 'Surrey Snooker Academy';

    $optionsArray = [
        'name' => $host,
        'host' => $host,
        'port' => $port,
    ];

    if ($user !== '') {
        $connCfg = [
            'username' => $user,
            'password' => $pw,
        ];

        if ($type === 'smtp-tls') {
            $connCfg['ssl'] = 'tls';
        }

        $optionsArray['connection_class']  = $auth;
        $optionsArray['connection_config'] = $connCfg;
    }

    $options   = new \Zend\Mail\Transport\SmtpOptions($optionsArray);
    $transport = new \Zend\Mail\Transport\Smtp($options);

    $message = new \Zend\Mail\Message();
    $message->setEncoding('UTF-8');
    $message->setFrom($fromAddr, $fromName);
    $message->addTo($to, $toName);
    $message->setSubject($subject);
    $message->setBody($body);

    $transport->send($message);
}
