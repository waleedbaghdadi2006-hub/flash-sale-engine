<?php

return [
    'default' => env('BROADCAST_CONNECTION', 'null'),

    'connections' => [
        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                // These are server-side values: the app container reaches the
                // Reverb container through Docker's internal network.
                'host' => env('REVERB_BROADCAST_HOST', env('REVERB_HOST')),
                'port' => env('REVERB_BROADCAST_PORT', env('REVERB_PORT', 8080)),
                'scheme' => env('REVERB_BROADCAST_SCHEME', env('REVERB_SCHEME', 'http')),
                'useTLS' => env('REVERB_BROADCAST_SCHEME', env('REVERB_SCHEME', 'http')) === 'https',
                'path' => env('REVERB_SERVER_PATH', ''),
            ],
            'client_options' => [],
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],
    ],
];
