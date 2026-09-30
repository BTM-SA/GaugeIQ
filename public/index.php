<?php
declare(strict_types=1);

$config = require __DIR__ . '/../config/local.php';
require __DIR__ . '/../app/Database.php';
require __DIR__ . '/../app/PressureService.php';

date_default_timezone_set($config['app']['timezone']);

$db = new Database($config);
$service = new PressureService($config, $db->pdo());

try {
    $current = $service->fetchCurrent();
    $latest = $db->pdo()->query(
        'SELECT pressure_hpa, humidity_percent, wind_speed_kmh, wind_direction_degrees, observed_at FROM gaugeiq_pressure_readings ORDER BY id DESC LIMIT 1'
    )->fetch();

    $change = $latest ? $current['pressure_hpa'] - (float)$latest['pressure_hpa'] : 0.0;
} catch (Throwable $e) {
    $current = null;
    $latest = null;
    $change = 0.0;
    $error = $e->getMessage();
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#111827">
<link rel="manifest" href="manifest.json">
<link rel="stylesheet" href="css/app.css">
<title>GaugeIQ</title>
</head>
<body>
<main class="shell">
    <header>
        <div>
            <p class="eyebrow">GAUGЕIQ</p>
            <h1>Air Pressure</h1>
            <p class="muted"><?= htmlspecialchars($config['pressure']['location_name'], ENT_QUOTES) ?></p>
        </div>
        <button id="notifyButton" class="secondary" type="button">Enable alerts</button>
    </header>

    <section class="card hero">
        <?php if ($current): ?>
            <p class="label">Current pressure</p>
            <div class="pressure"><?= number_format($current['pressure_hpa'], 1) ?><span> hPa</span></div>
            <div class="<?= $change > 0 ? 'rise' : ($change < 0 ? 'fall' : 'steady') ?>">
                <?= $change > 0 ? '↑ Rising' : ($change < 0 ? '↓ Falling' : '→ Stable') ?>
                <?php if ($latest): ?>
                    <strong><?= $change >= 0 ? '+' : '' ?><?= number_format($change, 1) ?> hPa</strong>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <p>Pressure is currently unavailable.</p>
            <p class="muted"><?= htmlspecialchars($error ?? 'Try again later.', ENT_QUOTES) ?></p>
        <?php endif; ?>
    </section>

    <section class="card">
        <div class="row"><span>Alert threshold</span><strong>±<?= number_format((float)$config['pressure']['threshold_hpa'], 1) ?> hPa</strong></div>
        <div class="row"><span>Checks</span><strong>Every <?= (int)$config['pressure']['check_interval_minutes'] ?> minutes</strong></div>
    </section>

    <p id="status" class="status"></p>
</main>
<script src="js/app.js"></script>
</body>
</html>
