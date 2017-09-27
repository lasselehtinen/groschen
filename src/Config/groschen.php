<?php

return [
    'schilling' => [
        'hostname' => env('SCHILLING_WEB_SERVICES_HOSTNAME', 'prod-schilling.domain.com'),
        'port' => env('SCHILLING_WEB_SERVICES_PORT', '8888'),
        'username' => env('SCHILLING_WEB_SERVICES_USERNAME', 'webserviceuser'),
        'password' => env('SCHILLING_WEB_SERVICES_PASSWORD', 'foobar'),
        'company' => env('SCHILLING_WEB_SERVICES_COMPANY', '1001'),
    ],
];
