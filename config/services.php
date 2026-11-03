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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
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

    'brevo' => [
        'api_key' => env('BREVO_API_KEY'),
        'sender_name' => env('BREVO_SENDER_NAME', 'Livestock - Tag and Seal'),
        'sender_email' => env('BREVO_SENDER_EMAIL', 'vallerianmchau123456@gmail.com'),
    ],

    'livekit' => [
        'url' => env('LIVEKIT_URL', ''),
        'internal_url' => env('LIVEKIT_INTERNAL_URL', 'http://127.0.0.1:7880'),
        'api_key' => env('LIVEKIT_API_KEY', ''),
        'api_secret' => env('LIVEKIT_API_SECRET', ''),
        'egress_enabled' => env('LIVEKIT_EGRESS_ENABLED', false),
        'egress_output' => env('LIVEKIT_EGRESS_OUTPUT', 'recordings'),
    ],

    'webrtc' => [
        'stun' => env('WEBRTC_STUN', 'stun:stun.l.google.com:19302'),
        'turn_url' => env('WEBRTC_TURN_URL', ''),
        'turn_username' => env('WEBRTC_TURN_USERNAME', ''),
        'turn_credential' => env('WEBRTC_TURN_CREDENTIAL', ''),
    ],

];
