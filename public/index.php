<?php
declare(strict_types=1);

$config = $configPath = __DIR__ . '/../config/local.php';
if (!is_file($configPath)) {
    header('Location: install/');
    exit;
}
$config = require $configPath;
require __DIR__ . '/../app/Database.php';
require __DIR__ . '/../app/Schema.php';
require __DIR__ . '/../app/PressureService.php';
require __DIR__ . '/../app/Version.php';
require __DIR__ . '/../app/AdminAuth.php';
date_default_timezone_set($config['app']['timezone']);

$db = new Database($config);
AdminAuth::startSession();
$csrfToken = AdminAuth::csrfToken();
$service = new PressureService($config, $db->pdo());
$pdo = $db->pdo();
migrateDatabase($pdo);

try {
    $current = $service->fetchCurrent();
    $latest = $db->pdo()->query(
        'SELECT pressure_hpa, humidity_percent, wind_speed_kmh, wind_direction_degrees, observed_at FROM gaugeiq_pressure_readings ORDER BY id DESC LIMIT 1'
    )->fetch();

    $change = $latest ? $current['pressure_hpa'] - (float)$latest['pressure_hpa'] : 0.0;

    $settings = [];
    foreach ($pdo->query("SELECT `key`, `value` FROM gaugeiq_settings WHERE `key` IN ('monitor_last_success_at', 'monitor_last_error')") as $setting) {
        $settings[(string)$setting['key']] = (string)$setting['value'];
    }
    $lastMonitorAt = $settings['monitor_last_success_at'] ?? null;
    $monitorError = $settings['monitor_last_error'] ?? '';
    $subscriptionCount = (int)$pdo->query('SELECT COUNT(*) FROM gaugeiq_push_subscriptions')->fetchColumn();
    $enabledRuleCount = (int)$pdo->query('SELECT COUNT(*) FROM gaugeiq_alert_rules WHERE enabled = 1')->fetchColumn();
    $recentAlerts = $pdo->query(
        'SELECT e.id, e.message, e.observed_at, r.name FROM gaugeiq_alert_events e LEFT JOIN gaugeiq_alert_rules r ON r.id = e.rule_id ORDER BY e.id DESC LIMIT 8'
    )->fetchAll();
} catch (Throwable $e) {
    $current = null;
    $latest = null;
    $change = 0.0;
    $error = $e->getMessage();
    $lastMonitorAt = null;
    $monitorError = '';
    $subscriptionCount = 0;
    $enabledRuleCount = 0;
    $recentAlerts = [];
}

$checkMinutes = max(1, (int)$config['pressure']['check_interval_minutes']);
// The cPanel cron is scheduled hourly in production. Keep monitoring health
// separate from the weather/alert check interval so an hourly cron is not
// incorrectly reported as overdue.
$monitorCronMinutes = 60;
$nextMonitorAt = $lastMonitorAt ? strtotime($lastMonitorAt) + ($monitorCronMinutes * 60) : null;
$monitorAge = $lastMonitorAt ? time() - (int)strtotime($lastMonitorAt) : null;
$monitorHealthy = $monitorAge !== null && $monitorAge <= ($monitorCronMinutes * 60 * 1.5);
$cronScript = realpath(__DIR__ . '/../cron/check-pressure.php') ?: (__DIR__ . '/../cron/check-pressure.php');
$cronCommand = '/usr/local/bin/php -q ' . escapeshellarg($cronScript);
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
    <div id="updateNotice" class="update-notice" hidden role="status">
        <strong id="updateNoticeTitle">GaugeIQ update available</strong>
        <span id="updateNoticeText"></span>
        <a id="updateNoticeLink" href="#" target="_blank" rel="noopener">View release</a>
    </div>

    <header>
        <div>
            <p class="eyebrow">GAUGЕIQ</p>
            <h1>GaugeIQ</h1>
            <p class="muted"><?= htmlspecialchars($config['pressure']['location_name'], ENT_QUOTES) ?></p>
        </div>
        <div class="header-actions">
            <a href="login.php" class="secondary button-link">Admin</a>
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

    <section class="card satellite-card" aria-labelledby="satelliteTitle">
        <div class="section-heading">
            <div>
                <h2 id="satelliteTitle">Satellite</h2>
                <p class="muted">Southern Africa · Meteosat-12 · updated every 10 minutes</p>
            </div>
        </div>
        <div class="satellite-player">
            <iframe
                src="https://www.youtube.com/embed/live_stream?channel=UCiN59j5b1fAGnXVzIYFpaMw&autoplay=1&mute=1&rel=0&modestbranding=1&playsinline=1"
                title="EUMETSAT Earth view - Africa"
                loading="lazy"
                allow="autoplay; encrypted-media; picture-in-picture"
                allowfullscreen></iframe>
        </div>
        <p class="satellite-caption">Live Earth imagery from EUMETSAT's Meteosat-12 Africa stream.</p>
    </section>

    <section class="card history-card">
        <div class="section-heading">
            <div>
                <h2 id="historyTitle">Last 24 hours</h2>
                <p id="historyStatus" class="muted">Loading history…</p>
            </div>
        </div>
        <div class="history-range" role="group" aria-label="History range">
            <button type="button" class="history-range-button" data-hours="6">6h</button>
            <button type="button" class="history-range-button active" data-hours="24">24h</button>
            <button type="button" class="history-range-button" data-hours="48">48h</button>
            <button type="button" class="history-range-button" data-hours="168">7d</button>
        </div>
        <div class="chart-block"><h3>Pressure</h3><canvas id="pressureChart" height="220"></canvas></div>
        <div class="chart-block"><h3>Humidity</h3><canvas id="humidityChart" height="220"></canvas></div>
        <div class="chart-block"><h3>Wind speed</h3><canvas id="windChart" height="220"></canvas></div>
    </section>

    <?php if (!$monitorHealthy): ?>
    <section class="card cron-setup-card" aria-labelledby="cronSetupTitle">
        <div class="section-heading">
            <div>
                <h2 id="cronSetupTitle">Monitoring setup</h2>
                <p class="muted">GaugeIQ has not seen a recent successful scheduled check. Make sure the cPanel Cron Job is configured to run every <?= $checkMinutes ?> minutes.</p>
            </div>
            <span class="status-pill status-warn">● Cron check needed</span>
        </div>
        <div class="cron-command-wrap">
            <code id="cronCommand"><?= htmlspecialchars($cronCommand, ENT_QUOTES) ?></code>
            <button type="button" class="secondary cron-copy-button" id="cronCopyButton">Copy command</button>
        </div>
        <p class="cron-help">In cPanel, open <strong>Cron Jobs</strong>, choose <strong>Every <?= $checkMinutes ?> minutes</strong>, paste the command above, and save it. You only need to do this once. Once a scheduled check succeeds, this setup reminder will disappear.</p>
        <p id="cronCopyStatus" class="cron-copy-status" role="status"></p>
    </section>
    <?php endif; ?>

    <section class="card monitoring-card" aria-labelledby="monitoringTitle">
        <div class="section-heading">
            <div><h2 id="monitoringTitle">Monitoring status</h2><p class="muted">GaugeIQ's scheduled background monitor.</p></div>
            <span class="status-pill <?= $monitorHealthy ? 'status-good' : 'status-warn' ?>"><?= $monitorHealthy ? '● Monitoring active' : '● Check required' ?></span>
        </div>
        <div class="status-grid">
            <div><span>Last successful check</span><strong><?= $lastMonitorAt ? htmlspecialchars(date('d M, H:i', (int)strtotime($lastMonitorAt)), ENT_QUOTES) : 'Not yet' ?></strong></div>
            <div><span>Next expected check</span><strong><?= $nextMonitorAt ? htmlspecialchars(date('d M, H:i', $nextMonitorAt), ENT_QUOTES) : 'Waiting for cron' ?></strong></div>

        <div class="device-view-row"><div><span>Notifications</span><strong><?= $subscriptionCount > 0 ? $subscriptionCount . ' device' . ($subscriptionCount === 1 ? '' : 's') . ' registered' : 'Not enabled' ?></strong></div><button type="button" class="device-view-button secondary" id="deviceViewButton" aria-expanded="false">View</button></div>
        <div id="deviceList" class="device-list" hidden></div>
            <div><span>Active alert rules</span><strong><?= $enabledRuleCount ?></strong></div>
        </div>
        <?php if ($monitorError): ?><p class="monitor-warning">The last scheduled check reported an error. <?= htmlspecialchars($monitorError, ENT_QUOTES) ?></p><?php endif; ?>
    </section>

    <section class="card alert-history-card" aria-labelledby="alertHistoryTitle">
        <div class="section-heading">
            <div><h2 id="alertHistoryTitle">Alert history</h2><p class="muted">Recent conditions that triggered your saved rules.</p></div>
            <a class="history-link" href="login.php">Manage alerts in Admin</a>
        </div>
        <?php if (!$recentAlerts): ?>
            <p class="muted empty-history">No alerts have been triggered yet.</p>
        <?php else: ?>
            <div class="alert-history-list">
                <?php foreach ($recentAlerts as $alert): ?>
                    <article class="alert-history-item">
                        <div class="alert-history-icon">!</div>
                        <div class="alert-history-content">
                            <strong><?= htmlspecialchars((string)($alert['name'] ?: 'GaugeIQ alert'), ENT_QUOTES) ?></strong>
                            <p><?= htmlspecialchars((string)$alert['message'], ENT_QUOTES) ?></p>
                            <time datetime="<?= htmlspecialchars((string)$alert['observed_at'], ENT_QUOTES) ?>"><?= htmlspecialchars(date('d M, H:i', (int)strtotime((string)$alert['observed_at'])), ENT_QUOTES) ?></time>
                            <form method="post" action="delete-alert-history.php" onsubmit="return confirm('Delete this alert history record?')">
                                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>"><input type="hidden" name="id" value="<?= (int)$alert['id'] ?>">
                                <button type="submit" class="alert-history-delete">Delete record</button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="card appearance-card" aria-labelledby="appearanceTitle"><div class="section-heading"><div><h2 id="appearanceTitle">Appearance</h2><p class="muted">Choose how GaugeIQ looks on this device.</p></div><select id="themeSelect" class="theme-select" aria-label="Appearance"><option value="system">Follow device</option><option value="light">Light</option><option value="dark">Dark</option></select></div></section>

    <p id="status" class="status"></p>
</main>
<script src="js/app.js"></script>
</body>
</html>
