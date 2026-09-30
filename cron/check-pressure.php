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

function setGaugeIqStatus(PDO $pdo, string $key, string $value): void
{
    $exists = $pdo->prepare('SELECT 1 FROM gaugeiq_settings WHERE `key` = ?');
    $exists->execute([$key]);
    if ($exists->fetchColumn() === false) {
        $stmt = $pdo->prepare('INSERT INTO gaugeiq_settings (`key`, `value`) VALUES (?, ?)');
    } else {
        $stmt = $pdo->prepare('UPDATE gaugeiq_settings SET `value` = ? WHERE `key` = ?');
        $stmt->execute([$value, $key]);
        return;
    }
    $stmt->execute([$key, $value]);
}

try {
    $current = $service->fetchCurrent();
$previous = $service->record($current);
    setGaugeIqStatus($pdo, 'monitor_last_success_at', gmdate('c'));
    setGaugeIqStatus($pdo, 'monitor_last_error', '');

} catch (Throwable $e) {
    try {
        setGaugeIqStatus($pdo, 'monitor_last_error', $e->getMessage());
    } catch (Throwable) {
        // Preserve the original monitoring error when status storage is unavailable.
    }
    fwrite(STDERR, "Weather monitoring failed: " . $e->getMessage() . "\n");
    exit(1);
}

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
    // Record the alert event independently of push delivery. A triggered rule
    // remains part of GaugeIQ history even when no device is subscribed.
    foreach ($ruleMatches as $match) {
        $ruleService->markTriggered(
            (int)$match['id'],
            (string)$match['message'],
            (string)$current['observed_at']
        );
    }

    $push = new PushService($config, $pdo);
    $sent = $push->send(
        'GaugeIQ weather alert',
        implode(' ', $alerts)
    );

    if ($sent > 0) {
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
