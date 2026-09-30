<?php
declare(strict_types=1);

$config = require __DIR__ . '/../config/local.php';
require __DIR__ . '/../app/Database.php';
require __DIR__ . '/../app/PressureService.php';
require __DIR__ . '/../app/PushService.php';
require __DIR__ . '/../app/Schema.php';
require __DIR__ . '/../app/AlertRuleService.php';

date_default_timezone_set($config['app']['timezone']);

$db = new Database($config);
$pdo = $db->pdo();
migrateDatabase($pdo);
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

$ruleService = new AlertRuleService($pdo);
$ruleMatches = $previous === null ? [] : $ruleService->evaluate($previous, $current);
$alerts = array_map(
    static fn(array $match): string => $match['message'],
    $ruleMatches
);

// Keep the original pressure configuration working for installations that have
// not yet created any alert rules in the Settings screen.
if ($ruleMatches === [] && $previous !== null && $service->pressureThresholdExceeded($previous, $current['pressure_hpa'])) {
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
if ($alerts === []) {
    exit;
}

try {
    $push = new PushService($config, $pdo);
    $sent = $push->send(
        'GaugeIQ weather alert',
        implode(' ', $alerts)
    );

    if ($sent > 0) {
        foreach ($ruleMatches as $match) {
            $ruleService->markTriggered(
                (int)$match['id'],
                (string)$match['message'],
                (string)$current['observed_at']
            );
        }

        if ($ruleMatches === [] && $service->pressureThresholdExceeded($previous, $current['pressure_hpa'])) {
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
    }

    printf("Weather alert sent to %d device(s).\n", $sent);
} catch (Throwable $e) {
    fwrite(STDERR, "Push delivery failed: " . $e->getMessage() . "\n");
    exit(1);
}
