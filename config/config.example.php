<?php
declare(strict_types=1);

return [
    'app' => [
        'name' => 'GaugeIQ',
        'base_url' => 'https://example.com/gaugeiq',
        'timezone' => 'Africa/Johannesburg',
    ],

    'database' => [
        'path' => __DIR__ . '/../storage/gaugeiq.sqlite',
    ],

    'pressure' => [
        'latitude' => -33.0153,
        'longitude' => 27.9116,
        'location_name' => 'East London',
        'threshold_hpa' => 3.0,
        'check_interval_minutes' => 15,
    ],

    'push' => [
        // Generate VAPID credentials before enabling production push.
        'subject' => 'mailto:admin@example.com',
        'public_key' => '',
        'private_key' => '',
    ],
];
