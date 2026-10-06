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
// The cPanel cron is scheduled every 30 minutes in production. Keep monitoring
// health separate from the weather/alert check interval so the actual cron
// cadence is not incorrectly reported as overdue.
$monitorCronMinutes = 30;
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

    <?php
    $temperatureC = $current ? (float)($current['temperature_c'] ?? 0.0) : 0.0;
    $dewPointC = $current ? (float)($current['dew_point_c'] ?? 0.0) : 0.0;
    $feelsLikeC = $current ? (float)($current['feels_like_c'] ?? $temperatureC) : 0.0;
    $forecastHighC = $current ? ($current['forecast_high_c'] ?? null) : null;
    $forecastLowC = $current ? ($current['forecast_low_c'] ?? null) : null;
    $humidityPercent = $current ? max(0.0, min(100.0, (float)$current['humidity_percent'])) : 0.0;
    $humidityZone = !$current ? 'Unavailable' : ($humidityPercent < 40 ? 'Dry' : ($humidityPercent < 60 ? 'Comfortable' : ($humidityPercent < 75 ? 'Humid' : 'Condensation risk')));
    $humidityZoneClass = strtolower(str_replace(' ', '-', $humidityZone));

    // Two separate circular environmental instruments. Keep their centres
    // deliberately apart so the dials and their labels share the same axis.
    $climateGaugePoint = static function (float $cx, float $cy, float $angle, float $radius): array {
        $radians = deg2rad($angle);
        return [$cx + cos($radians) * $radius, $cy + sin($radians) * $radius];
    };
    $tempRatio = $current ? max(0.0, min(1.0, $temperatureC / 40.0)) : 0.0;
    $dewRatio = $current ? max(0.0, min(1.0, $dewPointC / 40.0)) : 0.0;
    $humidityRatio = $current ? $humidityPercent / 100.0 : 0.0;

    $tempStart = 210.0; $tempSweep = 120.0;
    $dewStart = 115.0; $dewSweep = 310.0;
    $humidityStart = 115.0; $humiditySweep = 310.0;
    $dewAngle = $dewStart + $dewRatio * $dewSweep;
    $humidityAngle = $humidityStart + $humidityRatio * $humiditySweep;

    $dewCx = 25.0; $dewCy = 50.0; $smallR = 20.0;
    $humidityCx = 75.0; $humidityCy = 50.0;

    $climateNeedlePath = static function (float $cx, float $cy, float $angle, float $length): string {
        $radians = deg2rad($angle);
        $perpX = -sin($radians);
        $perpY = cos($radians);
        $tipX = $cx + cos($radians) * $length;
        $tipY = $cy + sin($radians) * $length;
        $midX = $cx + cos($radians) * ($length * 0.42);
        $midY = $cy + sin($radians) * ($length * 0.42);
        $baseX = $cx - cos($radians) * 1.4;
        $baseY = $cy - sin($radians) * 1.4;
        $fmt = static fn(array $p): string => implode(' ', array_map(static fn(float $v): string => number_format($v, 3, '.', ''), $p));
        return 'M ' . $fmt([$baseX + $perpX * 0.9, $baseY + $perpY * 0.9])
            . ' L ' . $fmt([$midX + $perpX * 0.28, $midY + $perpY * 0.28])
            . ' L ' . $fmt([$tipX + $perpX * 0.045, $tipY + $perpY * 0.045])
            . ' L ' . $fmt([$tipX - $perpX * 0.045, $tipY - $perpY * 0.045])
            . ' L ' . $fmt([$midX - $perpX * 0.28, $midY - $perpY * 0.28])
            . ' L ' . $fmt([$baseX - $perpX * 0.9, $baseY - $perpY * 0.9]) . ' Z';
    };

    $dewNeedlePath = $climateNeedlePath($dewCx, $dewCy, $dewAngle, $smallR - 2.5);
    $humidityNeedlePath = $climateNeedlePath($humidityCx, $humidityCy, $humidityAngle, $smallR - 2.5);
    ?> 


        <article class="card metric-card temperature-summary-card">
            <div class="section-heading">
                <div>
                    <p class="label">Temperature</p>
                    <p class="metric-caption">Today's outlook</p>
                </div>
            </div>
            <?php if ($current): ?>
                <div class="temperature-summary-main">
                    <div class="temperature-summary-current">
                        <strong><?= number_format($temperatureC, 1) ?>°C</strong>
                        <span>Feels like <?= number_format($feelsLikeC, 1) ?>°C</span>
                    </div>
                    <div class="temperature-summary-range">
                        <div><span>Low</span><strong><?= $forecastLowC !== null ? number_format((float)$forecastLowC, 1) . '°' : '—' ?></strong></div>
                        <div><span>High</span><strong><?= $forecastHighC !== null ? number_format((float)$forecastHighC, 1) . '°' : '—' ?></strong></div>
                    </div>
                </div>
                <div class="temperature-comfort-row">
                    <span>Comfort</span>
                    <b class="humidity-zone <?= htmlspecialchars($humidityZoneClass, ENT_QUOTES) ?>"><?= htmlspecialchars($humidityZone, ENT_QUOTES) ?></b>
                </div>
            <?php else: ?>
                <div class="metric-unavailable">Unavailable</div>
            <?php endif; ?>
        </article>

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



    <section class="card climate-gauge-card" aria-labelledby="climateGaugeTitle">
        <div class="section-heading">
            <div>
                <h2 id="climateGaugeTitle">Dew Point &amp; Humidity</h2>
                <p class="muted">Environmental instrument</p>
            </div>
        </div>

        <div class="climate-gauge-wrap">
            <div class="climate-triple-gauge" role="img" aria-label="<?= $current ? htmlspecialchars('Dew point ' . number_format($dewPointC, 1) . ' degrees Celsius. Relative humidity ' . number_format($humidityPercent, 0) . ' percent.', ENT_QUOTES) : 'Dew point and humidity unavailable' ?>">
                <svg viewBox="0 28 100 44" preserveAspectRatio="xMidYMid meet" aria-hidden="true" focusable="false">
                    <!-- Two near-complete circular instruments with a small opening at the bottom. -->
                    <path class="aircraft-unified-track dew" d="M <?= number_format($climateGaugePoint($dewCx,$dewCy,$dewStart, $smallR)[0],3,'.','') ?> <?= number_format($climateGaugePoint($dewCx,$dewCy,$dewStart, $smallR)[1],3,'.','') ?> A <?= $smallR ?> <?= $smallR ?> 0 1 1 <?= number_format($climateGaugePoint($dewCx,$dewCy,$dewStart + $dewSweep, $smallR)[0],3,'.','') ?> <?= number_format($climateGaugePoint($dewCx,$dewCy,$dewStart + $dewSweep, $smallR)[1],3,'.','') ?>"></path>
                    <path class="aircraft-unified-dew" pathLength="100" stroke-dasharray="<?= number_format($dewRatio * 100, 2, '.', '') ?> 100" d="M <?= number_format($climateGaugePoint($dewCx,$dewCy,$dewStart, $smallR)[0],3,'.','') ?> <?= number_format($climateGaugePoint($dewCx,$dewCy,$dewStart, $smallR)[1],3,'.','') ?> A <?= $smallR ?> <?= $smallR ?> 0 1 1 <?= number_format($climateGaugePoint($dewCx,$dewCy,$dewStart + $dewSweep, $smallR)[0],3,'.','') ?> <?= number_format($climateGaugePoint($dewCx,$dewCy,$dewStart + $dewSweep, $smallR)[1],3,'.','') ?>"></path>

                    <path class="aircraft-unified-track humidity" d="M <?= number_format($climateGaugePoint($humidityCx,$humidityCy,$humidityStart, $smallR)[0],3,'.','') ?> <?= number_format($climateGaugePoint($humidityCx,$humidityCy,$humidityStart, $smallR)[1],3,'.','') ?> A <?= $smallR ?> <?= $smallR ?> 0 1 1 <?= number_format($climateGaugePoint($humidityCx,$humidityCy,$humidityStart + $humiditySweep, $smallR)[0],3,'.','') ?> <?= number_format($climateGaugePoint($humidityCx,$humidityCy,$humidityStart + $humiditySweep, $smallR)[1],3,'.','') ?>"></path>
                    <path class="aircraft-unified-humidity" pathLength="100" stroke-dasharray="<?= number_format($humidityRatio * 100, 2, '.', '') ?> 100" d="M <?= number_format($climateGaugePoint($humidityCx,$humidityCy,$humidityStart, $smallR)[0],3,'.','') ?> <?= number_format($climateGaugePoint($humidityCx,$humidityCy,$humidityStart, $smallR)[1],3,'.','') ?> A <?= $smallR ?> <?= $smallR ?> 0 1 1 <?= number_format($climateGaugePoint($humidityCx,$humidityCy,$humidityStart + $humiditySweep, $smallR)[0],3,'.','') ?> <?= number_format($climateGaugePoint($humidityCx,$humidityCy,$humidityStart + $humiditySweep, $smallR)[1],3,'.','') ?>"></path>

                    <g class="aircraft-ticks small dew-ticks">
                        <?php for ($value = 0; $value <= 40; $value += 1):
                            $angle = $dewStart + ($value / 40.0) * $dewSweep;
                            $outer = $climateGaugePoint($dewCx,$dewCy,$angle,$smallR);
                            $inner = $climateGaugePoint($dewCx,$dewCy,$angle,$smallR - ($value % 5 === 0 ? 3.0 : 2.0));
                            [$lx,$ly] = $climateGaugePoint($dewCx,$dewCy,$angle,$smallR-5.5);
                        ?>
                            <line class="<?= $value % 5 === 0 ? 'major' : '' ?>" x1="<?= number_format($outer[0],3,'.','') ?>" y1="<?= number_format($outer[1],3,'.','') ?>" x2="<?= number_format($inner[0],3,'.','') ?>" y2="<?= number_format($inner[1],3,'.','') ?>"></line>
                            <?php if ($value % 5 === 0): ?><text x="<?= number_format($lx,3,'.','') ?>" y="<?= number_format($ly,3,'.','') ?>"><?= $value ?></text><?php endif; ?>
                        <?php endfor; ?>
                    </g>

                    <g class="aircraft-ticks small humidity-ticks">
                        <?php for ($value = 0; $value <= 2*50; $value += 2):
                            $angle = $humidityStart + ($value / 100.0) * $humiditySweep;
                            $outer = $climateGaugePoint($humidityCx,$humidityCy,$angle,$smallR);
                            $inner = $climateGaugePoint($humidityCx,$humidityCy,$angle,$smallR - ($value % 10 === 0 ? 3.0 : 2.0));
                            [$lx,$ly] = $climateGaugePoint($humidityCx,$humidityCy,$angle,$smallR-5.5);
                        ?>
                            <line class="<?= $value % 10 === 0 ? 'major' : '' ?>" x1="<?= number_format($outer[0],3,'.','') ?>" y1="<?= number_format($outer[1],3,'.','') ?>" x2="<?= number_format($inner[0],3,'.','') ?>" y2="<?= number_format($inner[1],3,'.','') ?>"></line>
                            <?php if ($value % 10 === 0): ?><text x="<?= number_format($lx,3,'.','') ?>" y="<?= number_format($ly,3,'.','') ?>"><?= $value ?></text><?php endif; ?>
                        <?php endfor; ?>
                    </g>

                    <path class="aircraft-needle dew-needle" d="<?= $dewNeedlePath ?>"></path>
                    <path class="aircraft-needle humidity-needle" d="<?= $humidityNeedlePath ?>"></path>

                    <circle class="aircraft-hub small" cx="25" cy="50" r="2.1"></circle>
                    <circle class="aircraft-hub small" cx="75" cy="50" r="2.1"></circle>


                </svg>
            </div>
            <div class="climate-readings" aria-label="Current climate readings">
                <div class="climate-reading">
                    <span>Dew Point</span>
                    <strong><?= $current ? number_format($dewPointC, 1) . '°C' : '—' ?></strong>
                </div>
                <div class="climate-reading">
                    <span>Humidity</span>
                    <strong><?= $current ? number_format($humidityPercent, 0) . '%' : '—' ?></strong>
                </div>
            </div>
        </div>
    </section>

    <section class="measurement-grid">


        <?php
        $windSpeed = $current ? max(0.0, (float)$current['wind_speed_kmh']) : 0.0;
        $windDegrees = $current ? fmod((float)$current['wind_direction_degrees'] + 360.0, 360.0) : 0.0;
        $windDirection = $current ? PressureService::directionLabel($windDegrees) : '—';
        $windToDegrees = fmod($windDegrees + 180.0, 360.0);
        $windSpeedMax = 40.0;
        $windSpeedPercent = min(100.0, ($windSpeed / $windSpeedMax) * 100.0);
        $windGaugeStart = 135.0;
        $windGaugeSweep = 270.0;
        $windGaugePoint = static function (float $angle, float $radius): array {
            $radians = deg2rad($angle);
            return [50.0 + cos($radians) * $radius, 50.0 + sin($radians) * $radius];
        };
        $windGaugeArcPoint = $windGaugePoint($windGaugeStart + (($windSpeedPercent / 100.0) * $windGaugeSweep), 40.0);
        $windGaugeArcLarge = $windSpeedPercent * $windGaugeSweep > 180.0 ? 1 : 0;
        ?>
        <article class="card metric-card wind-gauge-card">
            <div class="wind-gauge-heading">
                <div>
                    <p class="label">Wind</p>
                    <p class="metric-caption">Speed &amp; direction</p>
                </div>
                <div class="wind-compass-controls">
                    <span class="wind-compass-status" id="windCompassStatus">Compass off</span>
                    <button type="button" class="secondary wind-compass-button" id="windCompassButton">Enable compass</button>
                </div>
            </div>
            <div class="wind-gauge" role="img" aria-label="<?= $current ? htmlspecialchars(number_format($windSpeed, 1) . ' kilometers per hour, ' . $windDirection . ', ' . number_format($windDegrees, 0) . ' degrees', ENT_QUOTES) : 'Wind data unavailable' ?>">
                <svg viewBox="0 0 100 100" aria-hidden="true" focusable="false">
                    <path class="wind-speed-track" d="M 21.716 78.284 A 40 40 0 1 1 78.284 78.284"></path>
                    <path class="wind-speed-fill" pathLength="100" stroke-dasharray="<?= number_format($windSpeedPercent, 2, '.', '') ?> 100" d="M 21.716 78.284 A 40 40 0 1 1 78.284 78.284" <?= $current && $windSpeedPercent > 0 ? 'data-active="true"' : '' ?>></path>

                    <g class="wind-speed-ticks">
                        <?php for ($value = 0; $value <= 40; $value += 5):
                            $ratio = $value / $windSpeedMax;
                            $angle = $windGaugeStart + ($ratio * $windGaugeSweep);
                            $tickOuter = $windGaugePoint($angle, 47.0);
                            $tickInner = $windGaugePoint($angle, $value % 10 === 0 ? 43.0 : 44.5);
                            [$labelX, $labelY] = $windGaugePoint($angle, 38.5);
                        ?>
                            <line class="<?= $value % 10 === 0 ? 'major' : '' ?>" x1="<?= number_format($tickOuter[0], 3, '.', '') ?>" y1="<?= number_format($tickOuter[1], 3, '.', '') ?>" x2="<?= number_format($tickInner[0], 3, '.', '') ?>" y2="<?= number_format($tickInner[1], 3, '.', '') ?>"></line>
                            <?php if ($value % 20 === 0): ?>
                                <text x="<?= number_format($labelX, 3, '.', '') ?>" y="<?= number_format($labelY, 3, '.', '') ?>"><?= $value ?></text>
                            <?php endif; ?>
                        <?php endfor; ?>
                    </g>

                    <g class="wind-compass-orientation">
                        <circle class="wind-compass-ring" cx="50" cy="50" r="31"></circle>
                        <g class="wind-compass-ticks">
                            <?php
                            $compassLabels = [
                                0 => 'N', 45 => 'NE', 90 => 'E', 135 => 'SE',
                                180 => 'S', 225 => 'SW', 270 => 'W', 315 => 'NW'
                            ];
                            for ($degree = 0; $degree < 360; $degree += 15):
                                $svgAngle = $degree - 90.0;
                                $tickStart = $windGaugePoint($svgAngle, $degree % 45 === 0 ? 33.5 : 32.2);
                                $tickEnd = $windGaugePoint($svgAngle, 30.0);
                            ?>
                                <line class="<?= $degree % 45 === 0 ? 'major' : '' ?>" x1="<?= number_format($tickStart[0], 3, '.', '') ?>" y1="<?= number_format($tickStart[1], 3, '.', '') ?>" x2="<?= number_format($tickEnd[0], 3, '.', '') ?>" y2="<?= number_format($tickEnd[1], 3, '.', '') ?>"></line>
                            <?php endfor; ?>
                        </g>
                        <g class="wind-compass-labels">
                            <?php foreach ($compassLabels as $degree => $label):
                                [$labelX, $labelY] = $windGaugePoint($degree - 90.0, 26.5);
                            ?>
                                <text class="<?= strlen($label) > 1 ? 'minor' : '' ?>" x="<?= number_format($labelX, 3, '.', '') ?>" y="<?= number_format($labelY, 3, '.', '') ?>"><?= $label ?></text>
                            <?php endforeach; ?>
                        </g>
                    </g>

                    <circle class="wind-center" cx="50" cy="50" r="18.5"></circle>
                    <g class="wind-direction-marker" data-wind-degrees="<?= number_format($windDegrees, 2, '.', '') ?>">
                        <path d="M50 20 L54 29 L50 27 L46 29 Z"></path>
                    </g>
                    <g class="wind-direction-to-marker" data-wind-to-degrees="<?= number_format($windToDegrees, 2, '.', '') ?>">
                        <path style="fill:#22c55e" d="M50 20 L54 29 L50 27 L46 29 Z"></path>
                    </g>
                </svg>
                <div class="wind-gauge-center">
                    <strong><?= $current ? number_format($windSpeed, 1) : '—' ?><span>km/h</span></strong>
                    <small><?= $current ? number_format($windDegrees, 0) . '°' : '—' ?></small>
                </div>
            </div>
        </article>
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

    <section class="card satellite-card" aria-labelledby="satelliteTitle">
        <div class="section-heading">
            <div>
                <h2 id="satelliteTitle">MeteoSat-12</h2>
                <p class="muted">Updated every 10 minutes</p>
            </div>
        </div>
        <div class="satellite-player">
            <iframe
                src="https://www.youtube.com/embed/U3jRSL3y8Vc?autoplay=1&mute=1&rel=0&playsinline=1&controls=1&enablejsapi=1"
                title="EUMETSAT Earth view - Africa"
                loading="lazy"
                allow="autoplay; encrypted-media; picture-in-picture"
                allowfullscreen></iframe>
        </div>
        <p class="satellite-caption">Live Earth imagery from EUMETSAT's Meteosat-12 Africa stream.</p>
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
                                <button type="submit" class="alert-history-delete" aria-label="Delete record" title="Delete record"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 3h6l1 2h4v2H4V5h4l1-2Zm-3 6h12l-.8 11.2a2 2 0 0 1-2 1.8H8.8a2 2 0 0 1-2-1.8L6 9Zm4 2v8h2v-8h-2Zm4 0v8h2v-8h-2Z"/></svg></button>
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
<script src="js/app.js?v=20261001-compass"></script>
</body>
</html>
