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
<meta name="theme-color" content="#f3f4f6" id="themeColorMeta">
<link rel="manifest" href="manifest.json">
<link rel="stylesheet" href="css/app.css">
<title>GaugeIQ</title>
</head>
<body>
<main class="shell">
    <header>
        <div>
            <p class="eyebrow">GAUGЕIQ</p>
            <h1>GaugeIQ</h1>
            <p class="muted"><?= htmlspecialchars($config['pressure']['location_name'], ENT_QUOTES) ?></p>
        </div>
        <div class="header-actions">
            <a href="alerts.php" class="secondary button-link">Alerts</a>
            <button id="notifyButton" class="secondary" type="button">Enable alerts</button>
        </div>
    </header>

    <section class="measurement-grid">
        <article class="card metric-card pressure-card">
            <p class="label">Air pressure</p>
            <?php if ($current): ?>
                <div class="metric-value"><?= number_format($current['pressure_hpa'], 1) ?><span> hPa</span></div>
                <div class="metric-trend <?= $change > 0 ? 'rise' : ($change < 0 ? 'fall' : 'steady') ?>">
                    <?= $change > 0 ? '↑ Rising' : ($change < 0 ? '↓ Falling' : '→ Stable') ?>
                    <?php if ($latest): ?><strong><?= $change >= 0 ? '+' : '' ?><?= number_format($change, 1) ?> hPa</strong><?php endif; ?>
                </div>
            <?php else: ?>
                <div class="metric-unavailable">Unavailable</div>
            <?php endif; ?>
        </article>

        <article class="card metric-card">
            <p class="label">Humidity</p>
            <div class="metric-value"><?= $current ? number_format($current['humidity_percent'], 0) : '—' ?><span>%</span></div>
            <p class="metric-caption">Relative humidity</p>
        </article>

        <article class="card metric-card">
            <p class="label">Wind</p>
            <div class="metric-value"><?= $current ? number_format($current['wind_speed_kmh'], 1) : '—' ?><span> km/h</span></div>
            <p class="metric-caption"><?= $current ? htmlspecialchars(PressureService::directionLabel($current['wind_direction_degrees']), ENT_QUOTES) . ' · ' . number_format($current['wind_direction_degrees'], 0) . '°' : 'Direction unavailable' ?></p>
        </article>
    </section>

    <section class="card history-card">
        <div class="section-heading">
            <div>
                <h2>Last 24 hours</h2>
                <p id="historyStatus" class="muted">Loading history…</p>
            </div>
        </div>
        <div class="chart-block"><h3>Pressure</h3><canvas id="pressureChart" height="220"></canvas></div>
        <div class="chart-block"><h3>Humidity</h3><canvas id="humidityChart" height="220"></canvas></div>
        <div class="chart-block"><h3>Wind speed</h3><canvas id="windChart" height="220"></canvas></div>
    </section>

    <section class="card">
        <div class="row"><span>Pressure alert</span><strong>±<?= number_format((float)$config['pressure']['threshold_hpa'], 1) ?> hPa</strong></div>
        <div class="row"><span>Checks</span><strong>Every <?= (int)$config['pressure']['check_interval_minutes'] ?> minutes</strong></div>
        <div class="row"><span>Custom alerts</span><strong><a href="alerts.php">Manage</a></strong></div>
    </section>

    <section class="card appearance-card" aria-labelledby="appearanceTitle"><div class="section-heading"><div><h2 id="appearanceTitle">Appearance</h2><p class="muted">Choose how GaugeIQ looks on this device.</p></div><select id="themeSelect" class="theme-select" aria-label="Appearance"><option value="system">Follow device</option><option value="light">Light</option><option value="dark">Dark</option></select></div></section>

    <p id="status" class="status"></p>
</main>
<script src="js/app.js"></script>
</body>
</html>
