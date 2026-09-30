<?php
declare(strict_types=1);

$config = require __DIR__ . '/../config/local.php';
require __DIR__ . '/../app/Database.php';
require __DIR__ . '/../app/PressureService.php';

date_default_timezone_set($config['app']['timezone']);

$db = new Database($config);
$service = new PressureService($config, $db->pdo());

$current = $service->fetchCurrent();
$previous = $service->record($current['pressure_hpa'], $current['observed_at']);

printf(
    "GaugeIQ: %s hPa (%s)%s\n",
    number_format($current['pressure_hpa'], 1),
    $current['observed_at'],
    $previous === null ? '' : ' | change ' . number_format($current['pressure_hpa'] - $previous, 1) . ' hPa'
);

if ($service->thresholdExceeded($previous, $current['pressure_hpa'])) {
    echo "Threshold exceeded; push delivery will be added in the next milestone.\n";
}
