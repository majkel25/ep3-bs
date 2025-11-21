<?php

return [

    // ---------------------------------------------------------------------
    // SHOW FULL ERRORS ON SCREEN (TEMPORARY – TURN OFF IN PRODUCTION)
    // ---------------------------------------------------------------------
    'view_manager' => [
        'display_exceptions' => true,
    ],

    // ---------------------------------------------------------------------
    // DATABASE CONFIGURATION
    // ---------------------------------------------------------------------
    'db' => [
        'database' => getenv('MYSQL_DATABASE') ?: null,
        'username' => getenv('MYSQL_USER')     ?: null,
        'password' => getenv('MYSQL_PASSWORD') ?: null,
        'hostname' => getenv('DATABASE_URL')   ?: 'localhost',
        'port'     => getenv('DATABASE_PORT')  ?: null,
    ],

    // ---------------------------------------------------------------------
    // MAIL CONFIGURATION (BREVO SMTP)
    // ---------------------------------------------------------------------
    'mail' => [
        'type'    => getenv('MAIL_TYPE')    ?: 'smtp-tls',
        'address' => getenv('MAIL_ADDRESS') ?: 'info@example.com',

        'host' => getenv('MAIL_SMTP_HOST') ?: null,
        'user' => getenv('MAIL_SMTP_USER') ?: null,
        'pw'   => getenv('MAIL_SMTP_PW')   ?: null,

        'port' => getenv('MAIL_SMTP_PORT') ?: 587,
        'auth' => getenv('MAIL_SMTP_AUTH') ?: 'plain',
    ],

    // ---------------------------------------------------------------------
    // LANGUAGE / I18N
    // ---------------------------------------------------------------------
    'i18n' => [
        'choice' => [
            'en-US' => 'English',
            'de-DE' => 'Deutsch',
        ],
        'currency' => 'EUR',
        'locale'   => 'de-DE',
    ],

    // ---------------------------------------------------------------------
    // TWILIO CONFIG (FROM ENV VARS ONLY)
    // ---------------------------------------------------------------------
    'twilio' => [
        'sid'           => getenv('TWILIO_ACCOUNT_SID')        ?: null,
        'token'         => getenv('TWILIO_AUTH_TOKEN')         ?: null,
        'msg_service'   => getenv('TWILIO_MESSAGING_SERVICE_SID') ?: null,
        'whatsapp_from' => getenv('TWILIO_WHATSAPP_FROM')      ?: null,
    ],

    // ---------------------------------------------------------------------
    // EP3 DEV FLAG
    // ---------------------------------------------------------------------
    'ep3_bs_dev' => getenv('EP3_BS_DEV') ?: false,
];
