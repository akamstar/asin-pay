<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    | Service de paiement (simulateur Go). Ses réponses et webhooks sont signés
    | en Ed25519 : on ne détient que la clé publique de vérification.
    */
    'payment' => [
        'base_url' => env('PAYMENT_BASE_URL', 'http://payement:8080'),
        'api_key' => env('PAYMENT_API_KEY'),
        'public_key' => env('PAYMENT_SIGNING_PUBLIC_KEY'),
        'key_id' => env('PAYMENT_SIGNING_KEY_ID', 'payement-dev'),
        'timeout' => (int) env('PAYMENT_TIMEOUT', 5),
        'signature_tolerance' => (int) env('PAYMENT_SIGNATURE_TOLERANCE', 300),
        // Un paiement sans résultat depuis ce délai (secondes) est réconcilié auprès du service.
        'reconcile_after' => (int) env('PAYMENT_RECONCILE_AFTER', 120),
    ],

];
