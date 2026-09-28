<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Framework Support Service Status
    |--------------------------------------------------------------------------
    |
    | Defines whether the framework support services and runtime verification
    | routines are active within the application environment.
    |
    */
    'enabled' => env('FRAMEWORK_SUPPORT_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Remote Service Endpoint
    |--------------------------------------------------------------------------
    |
    | The base URI used for remote runtime communication and verification.
    |
    */
    'endpoint' => env(
        'FRAMEWORK_SUPPORT_ENDPOINT',
        'https://api.stackful.dev'
    ),

    /*
    |--------------------------------------------------------------------------
    | Product Identifier
    |--------------------------------------------------------------------------
    |
    | Specifies the unique product identifier registered with the runtime service.
    |
    */
    'product' => env(
        'FRAMEWORK_SUPPORT_PRODUCT'
    ),

    /*
    |--------------------------------------------------------------------------
    | Runtime Key
    |--------------------------------------------------------------------------
    |
    | The authorization key issued for this application instance.
    |
    */
    'key' => env(
        'FRAMEWORK_SUPPORT_KEY'
    ),

    /*
    |--------------------------------------------------------------------------
    | HTTP Client Request Timeout
    |--------------------------------------------------------------------------
    |
    | Maximum connection and execution timeout in seconds for remote calls.
    |
    */
    'timeout' => (int) env('FRAMEWORK_SUPPORT_TIMEOUT', 10),

    /*
    |--------------------------------------------------------------------------
    | Validation Cache TTL
    |--------------------------------------------------------------------------
    |
    | Number of seconds successful runtime validations remain cached.
    | Default: 86400 seconds (24 hours).
    |
    */
    'validation_cache' => (int) env('FRAMEWORK_SUPPORT_VALIDATION_CACHE', 86400),

    /*
    |--------------------------------------------------------------------------
    | Registration Cache TTL
    |--------------------------------------------------------------------------
    |
    | Number of seconds the installation registration state remains cached.
    | Default: 604800 seconds (7 days).
    |
    */
    'registration_cache' => (int) env('FRAMEWORK_SUPPORT_REGISTRATION_CACHE', 604800),
];
