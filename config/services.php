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

    'google_drive' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_REDIRECT_URI', env('APP_URL', 'http://localhost').'/admin/backup/google/callback'),
        'auth_uri' => 'https://accounts.google.com/o/oauth2/v2/auth',
        'token_uri' => 'https://oauth2.googleapis.com/token',
        'drive_uri' => 'https://www.googleapis.com/drive/v3',
        'upload_uri' => 'https://www.googleapis.com/upload/drive/v3/files',
        'scope' => 'https://www.googleapis.com/auth/drive.file',
        'backup_folder_name' => 'SIPETA Backup',
        'timeout' => 120,
        'upload_timeout' => 600,
        'max_retries' => 3,
    ],

];
