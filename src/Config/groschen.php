<?php

return [
    'elvis' => [
        'hostname' => env('ELVIS_HOSTNAME', 'elvis.domain.com'),
        'username' => env('ELVIS_USERNAME', 'elvisuser'),
        'password' => env('ELVIS_PASSWORD', 'foobar'),
    ],
    'mockingbird' => [
        'work_api_hostname' => env('MOCKINGBIRD_WORK_API_HOSTNAME'),
        'contact_api_hostname' => env('MOCKINGBIRD_CONTACT_API_HOSTNAME'),
        'clientId' => env('MOCKINGBIRD_CLIENT_ID'),
        'clientSecret' => env('MOCKINGBIRD_CLIENT_SECRET'),
        'urlAuthorize' => env('MOCKINGBIRD_OAUTH_BASE_URL').'/core/connect/authorize',
        'urlAccessToken' => env('MOCKINGBIRD_OAUTH_BASE_URL').'/core/connect/token',
        'urlResourceOwnerDetails' => env('MOCKINGBIRD_OAUTH_BASE_URL').'/core/connect/resource',
        'username' => env('MOCKINGBIRD_USERNAME'),
        'password' => env('MOCKINGBIRD_PASSWORD'),
    ],
];
