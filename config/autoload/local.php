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
        // These lines pull from DigitalOcean env vars; if for some reason
        // env vars are missing, the right-hand side default values are used.
        'database' => getenv('MYSQL_DATABASE') ?: 'ep3bs-clone',
        'username' => getenv('MYSQL_USER')     ?: 'ep3bsuser',
        'password' => getenv('MYSQL_PASSWORD') ?: 'AVNS_XfMp5FlwrzQR70__AIV',

        'hostname' => getenv('DATABASE_URL')   ?: 'ssacluster-do-user-63222099-0.c.db.ondigitalocean.com',
        'port'     => getenv('DATABASE_PORT')  ?: 25060,
    ],

    // ---------------------------------------------------------------------
    // MAIL CONFIGURATION (BREVO SMTP)
    // ---------------------------------------------------------------------
    'mail' => [
        'type'    => getenv('MAIL_TYPE')    ?: 'smtp-tls',
        'address' => getenv('MAIL_ADDRESS') ?: 'info@surreysnookeracademy.com',

        'host' => getenv('MAIL_SMTP_HOST') ?: 'smtp-relay.brevo.com',
        'user' => getenv('MAIL_SMTP_USER') ?: 'stevekentde@aol.com',
        'pw'   => getenv('MAIL_SMTP_PW')   ?: 'VnpmhsWwQx2PyBb1',

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
    // TWILIO CONFIG (FROM YOUR ENV VARS)
    // ---------------------------------------------------------------------
    'twilio' => [
        'sid'           => getenv('TWILIO_ACCOUNT_SID') ?: 'AC9db0e267ac37a45d4916cb7a5f504d67',
        'token'         => getenv('TWILIO_AUTH_TOKEN')   ?: '687b808c6af695df58a39018f33a2c6b',
        'msg_service'   => getenv('TWILIO_MESSAGING_SERVICE_SID') ?: 'MG02935162cf2a983db53d6102f177c1a5',
        'whatsapp_from' => getenv('TWILIO_WHATSAPP_FROM') ?: '+447575472568',
    ],

    // ---------------------------------------------------------------------
    // EP3 DEV FLAG
    // ---------------------------------------------------------------------
    'ep3_bs_dev' => getenv('EP3_BS_DEV') ?: false,
];
