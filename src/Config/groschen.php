<?php

return [
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
        'search_hostname' => env('OPUS_SEARCH_HOSTNAME'),
        'clientId' => env('OPUS_CLIENT_ID'),
        'clientSecret' => env('OPUS_CLIENT_SECRET'),
        'urlAuthorize' => env('OPUS_OAUTH_BASE_URL') . '/core/connect/authorize',
        'urlAccessToken' => env('OPUS_OAUTH_BASE_URL') . '/core/connect/token',
        'urlResourceOwnerDetails' => env('OPUS_OAUTH_BASE_URL') . '/core/connect/resource',
        'username' => env('OPUS_USERNAME'),
        'password' => env('OPUS_PASSWORD'),
    ],
];
