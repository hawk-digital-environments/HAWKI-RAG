<?php

return [
    'enabled' => env('AUTHZ_ENABLED', false),
    'document_api_enforced' => env('AUTHZ_DOCUMENT_API_ENFORCED', env('AUTHZ_ENABLED', false)),

    'oidc' => [
        'enabled' => env('OIDC_ENABLED', false),
        'issuer' => env('OIDC_ISSUER'),
        'audience' => env('OIDC_AUDIENCE'),
        'jwks_url' => env('OIDC_JWKS_URL'),
        'provider' => env('OIDC_PROVIDER', 'keycloak'),
        'leeway_seconds' => (int) env('OIDC_LEEWAY_SECONDS', 60),
    ],

    'graph' => [
        'backend' => env('AUTHZ_GRAPH_BACKEND', 'spicedb'),
        'timeout_seconds' => (int) env('AUTHZ_GRAPH_TIMEOUT_SECONDS', env('SPICEDB_TIMEOUT_SECONDS', 5)),
        'spicedb' => [
            'api_url' => env('SPICEDB_API_URL', 'http://spicedb:8443'),
            'preshared_key' => env('SPICEDB_PRESHARED_KEY'),
            'consistency' => env('SPICEDB_CONSISTENCY', 'minimize_latency'),
        ],
        'openfga' => [
            'api_url' => env('OPENFGA_API_URL', 'http://openfga:8080'),
            'store_id' => env('OPENFGA_STORE_ID'),
            'authorization_model_id' => env('OPENFGA_AUTHORIZATION_MODEL_ID'),
            'token' => env('OPENFGA_API_TOKEN'),
        ],
    ],

    'connectors' => [
        'default' => env('LMS_PERMISSION_CONNECTOR', 'static'),
        'static' => [
            'provider' => env('STATIC_LMS_PROVIDER', 'local'),
            'memberships' => env('STATIC_LMS_MEMBERSHIPS', ''),
            'documents' => env('STATIC_LMS_DOCUMENTS', ''),
        ],
        'studip' => [
            'enabled' => env('STUDIP_LMS_ENABLED', false),
            'base_url' => env('STUDIP_LMS_BASE_URL'),
            'token' => env('STUDIP_LMS_TOKEN'),
        ],
        'moodle' => ['enabled' => env('MOODLE_LMS_ENABLED', false)],
        'ilias' => ['enabled' => env('ILIAS_LMS_ENABLED', false)],
        'canvas' => ['enabled' => env('CANVAS_LMS_ENABLED', false)],
    ],
];
