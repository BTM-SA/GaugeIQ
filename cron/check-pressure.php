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
$previous = $service->record($current['pressure_hpa'], $current['observed_at']);
$change = $previous === null ? 0.0 : $current['pressure_hpa'] - $previous;

printf(
    "GaugeIQ: %s hPa (%s)%s
",
    number_format($current['pressure_hpa'], 1),
    $current['observed_at'],
    $previous === null ? '' : ' | change ' . number_format($change, 1) . ' hPa'
);

if (!$service->thresholdExceeded($previous, $current['pressure_hpa'])) {
    exit;
}

$baseline = $pdo->query(
    "SELECT value FROM settings WHERE key = 'last_notified_pressure_hpa'"
)->fetchColumn();

$baselinePressure = $baseline === false ? null : (float)$baseline;
$notificationChange = $baselinePressure === null
    ? $change
    : $current['pressure_hpa'] - $baselinePressure;

if ($baselinePressure !== null &&
    abs($notificationChange) < (float)$config['pressure']['threshold_hpa']) {
    echo "Threshold crossed from the previous reading, but not from the last notification baseline; no alert sent.
";
    exit;
}

$direction = $change >= 0 ? 'rising' : 'falling';
$body = sprintf(
    'Pressure is %s by %.1f hPa and is now %.1f hPa.',
    $direction,
    abs($change),
    $current['pressure_hpa']
);

try {
    $push = new PushService($config, $pdo);
    $sent = $push->send('GaugeIQ pressure alert', $body);

    $stmt = $pdo->prepare(
        "INSERT INTO settings (key, value) VALUES ('last_notified_pressure_hpa', ?)
         ON CONFLICT(key) DO UPDATE SET value = excluded.value"
    );
    $stmt->execute([(string)$current['pressure_hpa']]);

    printf("Push alert sent to %d device(s).\n", $sent);
} catch (Throwable $e) {
    fwrite(STDERR, "Push delivery failed: " . $e->getMessage() . "\n");
    exit(1);
}
