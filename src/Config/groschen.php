<?php

return [
    'schilling' => [
        'hostname' => env('SCHILLING_WEB_SERVICES_HOSTNAME', 'prod-schilling.domain.com'),
        'port' => env('SCHILLING_WEB_SERVICES_PORT', '8888'),
        'username' => env('SCHILLING_WEB_SERVICES_USERNAME', 'webserviceuser'),
        'password' => env('SCHILLING_WEB_SERVICES_PASSWORD', 'foobar'),
        'company' => env('SCHILLING_WEB_SERVICES_COMPANY', '1001'),
    ],
    'elvis' => [
        'hostname' => env('ELVIS_HOSTNAME', 'elvis.domain.com'),
        'username' => env('ELVIS_USERNAME', 'elvisuser'),
        'password' => env('ELVIS_PASSWORD', 'foobar'),
    ],
    'soundcloud' => [
        'clientId' => env('SOUNDCLOUD_CLIENTID'),
        'clientSecret' => env('SOUNDCLOUD_CLIENTSECRET'),
    ],
    'opus' => [
        'hostname' => env('OPUS_HOSTNAME'),
        'clientId' => env('OPUS_CLIENT_ID'),
        'clientSecret' => env('OPUS_CLIENT_SECRET'),
        'urlAuthorize' => env('OPUS_AUTHORIZE_URL'),
        'urlAccessToken' => env('OPUS_ACCESS_TOKEN_URL'),
        'urlResourceOwnerDetails' => env('OPUS_RESOURCE_OWNER_DETAILS_URL'),
        'username' => env('OPUS_USERNAME'),
        'password' => env('OPUS_PASSWORD'),
    ],
];
