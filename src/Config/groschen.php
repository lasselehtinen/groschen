<?php

return [
    'elvis' => [
        'hostname' => env('ELVIS_HOSTNAME', 'elvis.domain.com'), // @phpstan-ignore larastan.noEnvCallsOutsideOfConfig
        'username' => env('ELVIS_USERNAME', 'elvisuser'), // @phpstan-ignore larastan.noEnvCallsOutsideOfConfig
        'password' => env('ELVIS_PASSWORD', 'foobar'), // @phpstan-ignore larastan.noEnvCallsOutsideOfConfig
    ],
    'mockingbird' => [
        'work_api_hostname' => env('MOCKINGBIRD_WORK_API_HOSTNAME'), // @phpstan-ignore larastan.noEnvCallsOutsideOfConfig
        'contact_api_hostname' => env('MOCKINGBIRD_CONTACT_API_HOSTNAME'), // @phpstan-ignore larastan.noEnvCallsOutsideOfConfig
        'clientId' => env('MOCKINGBIRD_CLIENT_ID'), // @phpstan-ignore larastan.noEnvCallsOutsideOfConfig
        'clientSecret' => env('MOCKINGBIRD_CLIENT_SECRET'), // @phpstan-ignore larastan.noEnvCallsOutsideOfConfig
        'urlAuthorize' => env('MOCKINGBIRD_OAUTH_BASE_URL').'/core/connect/authorize', // @phpstan-ignore larastan.noEnvCallsOutsideOfConfig
        'urlAccessToken' => env('MOCKINGBIRD_OAUTH_BASE_URL').'/core/connect/token', // @phpstan-ignore larastan.noEnvCallsOutsideOfConfig
        'urlResourceOwnerDetails' => env('MOCKINGBIRD_OAUTH_BASE_URL').'/core/connect/resource', // @phpstan-ignore larastan.noEnvCallsOutsideOfConfig
        'username' => env('MOCKINGBIRD_USERNAME'), // @phpstan-ignore larastan.noEnvCallsOutsideOfConfig
        'password' => env('MOCKINGBIRD_PASSWORD'), // @phpstan-ignore larastan.noEnvCallsOutsideOfConfig
    ],
];
