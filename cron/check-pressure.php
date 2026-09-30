<?php
declare(strict_types=1);

$config = require __DIR__ . '/../config/local.php';
require __DIR__ . '/../app/Database.php';
require __DIR__ . '/../app/PressureService.php';
require __DIR__ . '/../app/PushService.php';

date_default_timezone_set($config['app']['timezone']);

$db = new Database($config);
$pdo = $db->pdo();
$service = new PressureService($config, $pdo);

$current = $service->fetchCurrent();
$previous = $service->record($current);

$pressureChange = $previous === null
    ? 0.0
    : $current['pressure_hpa'] - (float)$previous['pressure_hpa'];

printf(
    "GaugeIQ: %.1f hPa | %.0f%% humidity | %.1f km/h %s (%s)%s\n",
    $current['pressure_hpa'],
    $current['humidity_percent'],
    $current['wind_speed_kmh'],
    PressureService::directionLabel($current['wind_direction_degrees']),
    $current['wind_direction_degrees'],
    $current['observed_at'],
    $previous === null ? '' : ' | pressure change ' . number_format($pressureChange, 1) . ' hPa'
);

$alerts = [];

if ($service->pressureThresholdExceeded($previous, $current['pressure_hpa'])) {
    $baseline = $pdo->prepare(
        "SELECT value FROM gaugeiq_settings WHERE key = 'last_notified_pressure_hpa'"
    );
    $baseline->execute();
    $baselineValue = $baseline->fetchColumn();

    $baselinePressure = $baselineValue === false ? null : (float)$baselineValue;
    $notificationChange = $baselinePressure === null
        ? $pressureChange
        : $current['pressure_hpa'] - $baselinePressure;

    if ($baselinePressure === null ||
        abs($notificationChange) >= (float)$config['pressure']['threshold_hpa']) {
        $direction = $pressureChange >= 0 ? 'rising' : 'falling';
        $alerts[] = sprintf(
            'Pressure is %s by %.1f hPa to %.1f hPa.',
            $direction,
            abs($pressureChange),
            $current['pressure_hpa']
        );
    }
}

if ($service->humidityThresholdExceeded($previous, $current['humidity_percent'])) {
    $alerts[] = sprintf(
        'Humidity is now %.0f%%.',
        $current['humidity_percent']
    );
}

if ($service->windSpeedThresholdExceeded($previous, $current['wind_speed_kmh'])) {
    $alerts[] = sprintf(
        'Wind speed is now %.1f km/h.',
        $current['wind_speed_kmh']
    );
}

if ($service->windDirectionChanged($previous, $current['wind_direction_degrees'])) {
    $previousDirection = (float)$previous['wind_direction_degrees'];
    $change = $service->circularDifference($previousDirection, $current['wind_direction_degrees']);

    $alerts[] = sprintf(
        'Wind direction changed by %.0f° to %s.',
        $change,
        PressureService::directionLabel($current['wind_direction_degrees'])
    );
}

if ($previous !== null &&
    $service->windDirectionMatches($current['wind_direction_degrees']) &&
    !$service->windDirectionMatches((float)$previous['wind_direction_degrees'])) {
    $alerts[] = sprintf(
        'Wind is now coming from %s.',
        PressureService::directionLabel($current['wind_direction_degrees'])
    );
}

if ($alerts === []) {
    exit;
}

try {
    $push = new PushService($config, $pdo);
    $sent = $push->send(
        'GaugeIQ weather alert',
        implode(' ', $alerts)
    );

    if ($sent > 0 && $service->pressureThresholdExceeded($previous, $current['pressure_hpa'])) {
        $value = (string)$current['pressure_hpa'];

        $exists = $pdo->query(
            "SELECT 1 FROM gaugeiq_settings WHERE key = 'last_notified_pressure_hpa'"
        )->fetchColumn();

        if ($exists === false) {
            $stmt = $pdo->prepare(
                "INSERT INTO gaugeiq_settings (key, value) VALUES ('last_notified_pressure_hpa', ?)"
            );
            $stmt->execute([$value]);
        } else {
            $stmt = $pdo->prepare(
                "UPDATE gaugeiq_settings SET value = ? WHERE key = 'last_notified_pressure_hpa'"
            );
            $stmt->execute([$value]);
        }
    }

    printf("Weather alert sent to %d device(s).\n", $sent);
} catch (Throwable $e) {
    fwrite(STDERR, "Push delivery failed: " . $e->getMessage() . "\n");
    exit(1);
}
