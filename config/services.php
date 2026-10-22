<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
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

    // WhatsApp Cloud API (Meta). Las credenciales son de cada WISP (Configuración → Mensajería).
    'whatsapp' => [
        'graph_version' => env('WHATSAPP_GRAPH_VERSION', 'v23.0'),
    ],

    // Firebase Cloud Messaging (HTTP v1) para las notificaciones push de las apps móviles.
    // Es de la plataforma (un solo proyecto de Firebase para todos los WISP): JSON de la cuenta de servicio.
    'firebase' => [
        'credentials' => env('FIREBASE_CREDENTIALS', storage_path('app/private/firebase.json')),
    ],

];
