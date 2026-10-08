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

$locationName = (string)$config['pressure']['location_name'];
$locationSetting = $pdo->prepare("SELECT value FROM gaugeiq_settings WHERE `key` = 'location_name' LIMIT 1");
$locationSetting->execute();
$storedLocationName = $locationSetting->fetchColumn();
if ($storedLocationName !== false && trim((string)$storedLocationName) !== '') {
    $locationName = (string)$storedLocationName;
}

try {
    $current = $service->fetchCurrent();
    $latest = $db->pdo()->query(
        'SELECT pressure_hpa, humidity_percent, wind_speed_kmh, wind_direction_degrees, observed_at FROM gaugeiq_pressure_readings ORDER BY id DESC LIMIT 1'
    )->fetch();

    $change = $latest ? $current['pressure_hpa'] - (float)$latest['pressure_hpa'] : 0.0;
    $pressureHistory = $pdo->query('SELECT MIN(pressure_hpa) AS pressure_low, MAX(pressure_hpa) AS pressure_high FROM gaugeiq_pressure_readings')->fetch() ?: [];

    $settings = [];
    foreach ($pdo->query("SELECT `key`, `value` FROM gaugeiq_settings WHERE `key` IN ('monitor_last_success_at', 'monitor_last_error')") as $setting) {
        $settings[(string)$setting['key']] = (string)$setting['value'];
    }
    $lastMonitorAt = $settings['monitor_last_success_at'] ?? null;
    $monitorError = $settings['monitor_last_error'] ?? '';
    $subscriptionCount = (int)$pdo->query('SELECT COUNT(*) FROM gaugeiq_push_subscriptions')->fetchColumn();
    $enabledRuleCount = (int)$pdo->query('SELECT COUNT(*) FROM gaugeiq_alert_rules WHERE enabled = 1')->fetchColumn();
    $weatherChangeAlertThreshold = null;
    $weatherChangeAlertRules = $pdo->query("SELECT configuration_json FROM gaugeiq_alert_rules WHERE metric = 'weather_change' AND enabled = 1 AND condition_type = 'above'")->fetchAll();
    foreach ($weatherChangeAlertRules as $weatherChangeAlertRule) {
        $weatherChangeConfig = json_decode((string)$weatherChangeAlertRule['configuration_json'], true);
        $weatherChangeThreshold = isset($weatherChangeConfig['value']) ? (float)$weatherChangeConfig['value'] : null;
        if ($weatherChangeThreshold !== null && $weatherChangeThreshold >= 1 && $weatherChangeThreshold <= 10) {
            $weatherChangeAlertThreshold = $weatherChangeAlertThreshold === null
                ? $weatherChangeThreshold
                : min($weatherChangeAlertThreshold, $weatherChangeThreshold);
        }
    }
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
            <p class="muted"><?= htmlspecialchars($locationName, ENT_QUOTES) ?></p>
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
    $humidityZone = !$current ? 'Unavailable' : ($humidityPercent < 40 ? 'Dry' : ($humidityPercent < 60 ? 'Comfortable' : ($humidityPercent < 75 ? 'Humid' : 'Condensation')));
    $humidityZoneClass = strtolower(str_replace(' ', '-', $humidityZone));
    $rainfallMm = $current ? max(0.0, (float)($current['rainfall_mm'] ?? 0.0)) : 0.0;
    $cloudCoverPercent = $current ? max(0.0, min(100.0, (float)($current['cloud_cover_percent'] ?? 0.0))) : 0.0;
    $pressureLow = isset($pressureHistory['pressure_low']) ? (float)$pressureHistory['pressure_low'] : ($current ? (float)$current['pressure_hpa'] : 0.0);
    $pressureHigh = isset($pressureHistory['pressure_high']) ? (float)$pressureHistory['pressure_high'] : ($current ? (float)$current['pressure_hpa'] : 0.0);
    $pressureRange = max(0.1, $pressureHigh - $pressureLow);
    $pressureRatio = $current ? max(0.0, min(1.0, ((float)$current['pressure_hpa'] - $pressureLow) / $pressureRange)) : 0.0;
    $forecast = $current && is_array($current['forecast'] ?? null) ? $current['forecast'] : [];

    $forecastCondition = static function (?int $code): array {
        return match (true) {
            $code === null => ['icon' => '—', 'label' => 'Unavailable'],
            $code === 0 => ['icon' => '☀️', 'label' => 'Clear sky'],
            $code === 1 => ['icon' => '🌤️', 'label' => 'Mainly clear'],
            $code === 2 => ['icon' => '⛅', 'label' => 'Partly cloudy'],
            $code === 3 => ['icon' => '☁️', 'label' => 'Overcast'],
            in_array($code, [45, 48], true) => ['icon' => '🌫️', 'label' => 'Fog'],
            in_array($code, [51, 53, 55, 56, 57], true) => ['icon' => '🌦️', 'label' => 'Drizzle'],
            in_array($code, [61, 63, 65, 66, 67], true) => ['icon' => '🌧️', 'label' => 'Rain'],
            in_array($code, [71, 73, 75, 77, 85, 86], true) => ['icon' => '🌨️', 'label' => 'Snow'],
            in_array($code, [80, 81, 82], true) => ['icon' => '🌦️', 'label' => 'Rain showers'],
            in_array($code, [95, 96, 99], true) => ['icon' => '⛈️', 'label' => 'Thunderstorm'],
            default => ['icon' => '🌤️', 'label' => 'Mixed conditions'],
        };
    };

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
    $rainCx = 25.0; $rainCy = 50.0; $rainStart = 115.0; $rainSweep = 310.0; $rainR = 20.0;
    $cloudCx = 75.0; $cloudCy = 50.0; $cloudStart = 115.0; $cloudSweep = 310.0; $cloudR = 20.0;
    $rainRatio = max(0.0, min(1.0, $rainfallMm / 10.0));
    $cloudRatio = $cloudCoverPercent / 100.0;

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
    $rainNeedlePath = $climateNeedlePath($rainCx, $rainCy, $rainStart + $rainRatio * $rainSweep, $rainR - 2.5);
    $cloudNeedlePath = $climateNeedlePath($cloudCx, $cloudCy, $cloudStart + $cloudRatio * $cloudSweep, $cloudR - 2.5);
    ?> 


        <article class="card metric-card temperature-summary-card">
            <div class="temperature-card-heading"><p class="label">Temperature</p></div>
            <?php if ($current): ?>
                <div class="temperature-summary-main">
                    <div class="temperature-summary-current">
                        <strong><?= number_format($temperatureC, 1) ?>°C</strong>
                        <span>Feels like <?= number_format($feelsLikeC, 1) ?>°C</span>
                    </div>
                    <div class="temperature-summary-forecast">
                        <?php $todayCondition = $forecast ? $forecastCondition($forecast[0]['weather_code'] ?? null) : ['icon' => '—', 'label' => 'Unavailable']; ?>
                        <span class="temperature-forecast-icon" role="img" aria-label="<?= htmlspecialchars($todayCondition['label'], ENT_QUOTES) ?>"><?= $todayCondition['icon'] ?></span>
                        <div class="temperature-summary-range">
                            <div><span>High</span><strong><?= $forecastHighC !== null ? number_format((float)$forecastHighC, 1) . '°' : '—' ?></strong></div>
                            <div><span>Low</span><strong><?= $forecastLowC !== null ? number_format((float)$forecastLowC, 1) . '°' : '—' ?></strong></div>
                        </div>
                    </div>
                </div>
                <div class="temperature-stability-row">
                    <div class="temperature-stability-label">Stability</div>
                    <div class="temperature-stability-track" aria-label="Weather stability" data-weather-change-alert-threshold="<?= $weatherChangeAlertThreshold !== null ? htmlspecialchars((string)$weatherChangeAlertThreshold, ENT_QUOTES) : '' ?>"><div class="temperature-stability-fill" id="weatherStabilityFill" style="width:100%"></div></div>
                    <div class="temperature-comfort-inline">Comfort zone: <b class="humidity-zone <?= htmlspecialchars($humidityZoneClass, ENT_QUOTES) ?>"><?= htmlspecialchars($humidityZone, ENT_QUOTES) ?></b></div>
                </div>
            <?php else: ?><div class="metric-unavailable">Unavailable</div><?php endif; ?>
        </article>

        <article class="card metric-card pressure-card">
            <div class="pressure-card-heading">
                <div>
                    <p class="label">Air pressure</p>
                    <?php if ($current): ?>
                        <div class="pressure-readout-row">
                            <div class="metric-value"><?= number_format($current['pressure_hpa'], 1) ?><span> hPa</span></div>
                            <div class="pressure-range-gauge" aria-label="<?= $current ? htmlspecialchars('Current pressure ' . number_format((float)$current['pressure_hpa'], 1) . ' hPa, historical low ' . number_format($pressureLow, 1) . ', high ' . number_format($pressureHigh, 1), ENT_QUOTES) : 'Pressure range unavailable' ?>">
                                <svg viewBox="0 16 100 50" aria-hidden="true" focusable="false">
                                    <path class="pressure-range-track" d="M 18 55 A 32 32 0 0 1 82 55"></path>
                                    <g class="pressure-range-ticks">
                                        <?php for ($tick = 0; $tick <= 10; $tick++):
                                            $tickRatio = $tick / 10.0;
                                            $tickAngle = 180.0 + ($tickRatio * 180.0);
                                            $tickOuterX = 50 + cos(deg2rad($tickAngle)) * 33;
                                            $tickOuterY = 55 + sin(deg2rad($tickAngle)) * 33;
                                            $tickInnerRadius = $tick % 2 === 0 ? 27.5 : 29.5;
                                            $tickInnerX = 50 + cos(deg2rad($tickAngle)) * $tickInnerRadius;
                                            $tickInnerY = 55 + sin(deg2rad($tickAngle)) * $tickInnerRadius;
                                        ?>
                                            <line class="<?= $tick % 2 === 0 ? 'major' : '' ?>"
                                                  x1="<?= number_format($tickOuterX, 3, '.', '') ?>" y1="<?= number_format($tickOuterY, 3, '.', '') ?>"
                                                  x2="<?= number_format($tickInnerX, 3, '.', '') ?>" y2="<?= number_format($tickInnerY, 3, '.', '') ?>"></line>
                                        <?php endfor; ?>
                                    </g>
                                    <line class="pressure-range-needle" x1="50" y1="55"
                                          x2="<?= number_format(50 + cos(deg2rad(180 + $pressureRatio * 180)) * 28, 3, '.', '') ?>"
                                          y2="<?= number_format(55 + sin(deg2rad(180 + $pressureRatio * 180)) * 28, 3, '.', '') ?>"></line>
                                    <circle class="pressure-range-hub" cx="50" cy="55" r="2.8"></circle>
                                    <text x="14" y="63">L</text><text x="86" y="63">H</text>
                                </svg>
                            </div>
                        </div>
                        <div class="metric-trend <?= $change > 0 ? 'rise' : ($change < 0 ? 'fall' : 'steady') ?>">
                            <?= $change > 0 ? '↑ Rising' : ($change < 0 ? '↓ Falling' : '→ Stable') ?>
                            <?php if ($latest): ?><strong><?= $change >= 0 ? '+' : '' ?><?= number_format($change, 1) ?> hPa</strong><?php endif; ?>
                        </div>
                    <?php else: ?><div class="metric-unavailable">Unavailable</div><?php endif; ?>
                </div>
       </div>
        </article>

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
                    <div class="wind-summary-line">Speed &amp; direction</div>
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
                            $tickInner = $windGaugePoint($angle, $value % 10 === 0 ? 41.5 : 43.0);
                            [$labelX, $labelY] = $windGaugePoint($angle, 38.5);
                        ?>
                            <line class="<?= $value % 10 === 0 ? 'major' : '' ?>" x1="<?= number_format($tickOuter[0], 3, '.', '') ?>" y1="<?= number_format($tickOuter[1], 3, '.', '') ?>" x2="<?= number_format($tickInner[0], 3, '.', '') ?>" y2="<?= number_format($tickInner[1], 3, '.', '') ?>"></line>
                            <?php if ($value % 5 === 0): ?>
                                <text x="<?= number_format($labelX, 3, '.', '') ?>" y="<?= number_format($labelY, 3, '.', '') ?>"><?= $value ?></text>
                            <?php endif; ?>
                        <?php endfor; ?>
                    </g>

                    <g class="wind-compass-orientation">
                        <defs>
                        <radialGradient id="windCompassGlass" cx="38%" cy="28%" r="72%">
                            <stop offset="0%" stop-color="#ffffff" stop-opacity=".30"></stop>
                            <stop offset="34%" stop-color="#ffffff" stop-opacity=".10"></stop>
                            <stop offset="72%" stop-color="#ffffff" stop-opacity=".035"></stop>
                            <stop offset="100%" stop-color="#ffffff" stop-opacity=".12"></stop>
                        </radialGradient>
                    </defs>
                                        <circle class="wind-gauge-glass-overlay" cx="50" cy="50" r="47"></circle>
<circle class="wind-compass-ring" cx="50" cy="50" r="31"></circle>
                    <circle class="wind-compass-glass" cx="50" cy="50" r="29.9"></circle>
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
                    <g class="wind-direction-arrows" data-wind-degrees="<?= number_format($windDegrees, 2, '.', '') ?>">
                        <circle class="wind-direction-marker" cx="50" cy="20" r="2.7"></circle>
                        <path class="wind-direction-to-marker" d="M50 80 L54 71 L50 73 L46 71 Z"></path>
                    </g>
                </svg>
                <div class="wind-gauge-center">
                    <small><?= $current ? number_format($windDegrees, 0) . '°' : '—' ?></small>
                    <strong><?= $current ? number_format($windSpeed, 1) : '—' ?><span>km/h</span></strong>
                </div>
            </div>
        </article>
    </section>

    <section class="card climate-gauge-card" aria-labelledby="climateGaugeTitle">
        <div class="section-heading">
            <div>
                <h2 id="climateGaugeTitle">Dew Point &amp; Humidity</h2>
                <p class="muted">Environmental instrument</p>
            </div>
        </div>

        <div class="climate-gauge-wrap">
            <div class="climate-four-gauges" aria-label="Current climate gauges">
                <div class="climate-instrument">
                    <svg viewBox="25 25 50 50" preserveAspectRatio="xMidYMid meet" aria-hidden="true" focusable="false">
                        <path class="aircraft-unified-track" d="M 41.548 68.126 A 20 20 0 1 1 61.472 66.383"></path>
                        <path class="aircraft-unified-dew" pathLength="100" stroke-dasharray="<?= number_format($dewRatio * 100, 2, '.', '') ?> 100" d="M 41.548 68.126 A 20 20 0 1 1 61.472 66.383"></path>
                        <g class="aircraft-ticks small">
                            <?php for ($value = 0; $value <= 40; $value += 1):
                                $angle = $dewStart + ($value / 40.0) * $dewSweep;
                                $outer = $climateGaugePoint(50,50,$angle,$smallR);
                                $inner = $climateGaugePoint(50,50,$angle,$smallR - ($value % 5 === 0 ? 3.0 : 2.0));
                                [$lx,$ly] = $climateGaugePoint(50,50,$angle,$smallR-5.5);
                            ?>
                                <line class="<?= $value % 5 === 0 ? 'major' : '' ?>" x1="<?= number_format($outer[0],3,'.','') ?>" y1="<?= number_format($outer[1],3,'.','') ?>" x2="<?= number_format($inner[0],3,'.','') ?>" y2="<?= number_format($inner[1],3,'.','') ?>"></line>
                                <?php if ($value % 5 === 0): ?><text x="<?= number_format($lx,3,'.','') ?>" y="<?= number_format($ly,3,'.','') ?>"><?= $value ?></text><?php endif; ?>
                            <?php endfor; ?>
                        </g>
                        <path class="aircraft-needle dew-needle" d="<?= $climateNeedlePath(50,50,$dewAngle,$smallR-2.5) ?>"></path>
                        <circle class="aircraft-hub small" cx="50" cy="50" r="2.1"></circle>
                    </svg>
                    <span>Dew Point</span>
                    <strong><?= $current ? number_format($dewPointC, 1) . '°C' : '—' ?></strong>
                </div>

                <div class="climate-instrument">
                    <svg viewBox="25 25 50 50" preserveAspectRatio="xMidYMid meet" aria-hidden="true" focusable="false">
                        <path class="aircraft-unified-track" d="M 41.548 68.126 A 20 20 0 1 1 61.472 66.383"></path>
                        <path class="aircraft-unified-humidity" pathLength="100" stroke-dasharray="<?= number_format($humidityRatio * 100, 2, '.', '') ?> 100" d="M 41.548 68.126 A 20 20 0 1 1 61.472 66.383"></path>
                        <g class="aircraft-ticks small">
                            <?php for ($value = 0; $value <= 100; $value += 2):
                                $angle = $humidityStart + ($value / 100.0) * $humiditySweep;
                                $outer = $climateGaugePoint(50,50,$angle,$smallR);
                                $inner = $climateGaugePoint(50,50,$angle,$smallR - ($value % 10 === 0 ? 3.0 : 2.0));
                                [$lx,$ly] = $climateGaugePoint(50,50,$angle,$smallR-5.5);
                            ?>
                                <line class="<?= $value % 10 === 0 ? 'major' : '' ?>" x1="<?= number_format($outer[0],3,'.','') ?>" y1="<?= number_format($outer[1],3,'.','') ?>" x2="<?= number_format($inner[0],3,'.','') ?>" y2="<?= number_format($inner[1],3,'.','') ?>"></line>
                                <?php if ($value % 10 === 0): ?><text x="<?= number_format($lx,3,'.','') ?>" y="<?= number_format($ly,3,'.','') ?>"><?= $value ?></text><?php endif; ?>
                            <?php endfor; ?>
                        </g>
                        <path class="aircraft-needle humidity-needle" d="<?= $climateNeedlePath(50,50,$humidityAngle,$smallR-2.5) ?>"></path>
                        <circle class="aircraft-hub small" cx="50" cy="50" r="2.1"></circle>
                    </svg>
                    <span>Humidity</span>
                    <strong><?= $current ? number_format($humidityPercent, 0) . '%' : '—' ?></strong>
                </div>

                <div class="climate-instrument">
                    <svg viewBox="25 25 50 50" preserveAspectRatio="xMidYMid meet" aria-hidden="true" focusable="false">
                        <path class="aircraft-unified-track" d="M 41.548 68.126 A 20 20 0 1 1 61.472 66.383"></path>
                        <path class="aircraft-unified-rain" pathLength="100" stroke-dasharray="<?= number_format($rainRatio * 100, 2, '.', '') ?> 100" d="M 41.548 68.126 A 20 20 0 1 1 61.472 66.383"></path>
                        <g class="aircraft-ticks small">
                            <?php for ($value = 0; $value <= 10; $value += 1):
                                $angle = $rainStart + ($value / 10.0) * $rainSweep;
                                $outer = $climateGaugePoint(50,50,$angle,$smallR);
                                $inner = $climateGaugePoint(50,50,$angle,$smallR - ($value % 2 === 0 ? 3.0 : 2.0));
                                [$lx,$ly] = $climateGaugePoint(50,50,$angle,$smallR-5.5);
                            ?>
                                <line class="<?= $value % 2 === 0 ? 'major' : '' ?>" x1="<?= number_format($outer[0],3,'.','') ?>" y1="<?= number_format($outer[1],3,'.','') ?>" x2="<?= number_format($inner[0],3,'.','') ?>" y2="<?= number_format($inner[1],3,'.','') ?>"></line>
                                <?php if ($value % 2 === 0): ?><text x="<?= number_format($lx,3,'.','') ?>" y="<?= number_format($ly,3,'.','') ?>"><?= $value ?></text><?php endif; ?>
                            <?php endfor; ?>
                        </g>
                        <path class="aircraft-needle" d="<?= $climateNeedlePath(50,50,$rainStart + $rainRatio * $rainSweep,$smallR-2.5) ?>"></path>
                        <circle class="aircraft-hub small" cx="50" cy="50" r="2.1"></circle>
                    </svg>
                    <span>Rainfall</span>
                    <strong><?= $current ? number_format($rainfallMm, 1) . ' mm' : '—' ?></strong>
                </div>

                <div class="climate-instrument">
                    <svg viewBox="25 25 50 50" preserveAspectRatio="xMidYMid meet" aria-hidden="true" focusable="false">
                        <path class="aircraft-unified-track" d="M 41.548 68.126 A 20 20 0 1 1 61.472 66.383"></path>
                        <path class="aircraft-unified-cloud" pathLength="100" stroke-dasharray="<?= number_format($cloudRatio * 100, 2, '.', '') ?> 100" d="M 41.548 68.126 A 20 20 0 1 1 61.472 66.383"></path>
                        <g class="aircraft-ticks small">
                            <?php for ($value = 0; $value <= 100; $value += 2):
                                $angle = $cloudStart + ($value / 100.0) * $cloudSweep;
                                $outer = $climateGaugePoint(50,50,$angle,$smallR);
                                $inner = $climateGaugePoint(50,50,$angle,$smallR - ($value % 10 === 0 ? 3.0 : 2.0));
                                [$lx,$ly] = $climateGaugePoint(50,50,$angle,$smallR-5.5);
                            ?>
                                <line class="<?= $value % 10 === 0 ? 'major' : '' ?>" x1="<?= number_format($outer[0],3,'.','') ?>" y1="<?= number_format($outer[1],3,'.','') ?>" x2="<?= number_format($inner[0],3,'.','') ?>" y2="<?= number_format($inner[1],3,'.','') ?>"></line>
                                <?php if ($value % 10 === 0): ?><text x="<?= number_format($lx,3,'.','') ?>" y="<?= number_format($ly,3,'.','') ?>"><?= $value ?></text><?php endif; ?>
                            <?php endfor; ?>
                        </g>
                        <path class="aircraft-needle" d="<?= $climateNeedlePath(50,50,$cloudStart + $cloudRatio * $cloudSweep,$smallR-2.5) ?>"></path>
                        <circle class="aircraft-hub small" cx="50" cy="50" r="2.1"></circle>
                    </svg>
                    <span>Cloud Cover</span>
                    <strong><?= $current ? number_format($cloudCoverPercent, 0) . '%' : '—' ?></strong>
                </div>
            </div>
        </section>





    <section class="card forecast-card" aria-labelledby="forecastTitle">
        <div class="section-heading">
            <div>
                <h2 id="forecastTitle">4 day forecast</h2>
                <p class="muted">Open-Meteo forecast for <?= htmlspecialchars($locationName, ENT_QUOTES) ?></p>
            </div>
        </div>
        <div class="forecast-days">
            <?php foreach ($forecast as $day):
                $condition = $forecastCondition($day['weather_code'] ?? null);
                $rainProbability = max(0, min(100, (int)($day['rain_probability_percent'] ?? 0)));
                $dayLabel = date('D', strtotime((string)$day['date']));
                $high = $day['temperature_max_c'] ?? null;
                $low = $day['temperature_min_c'] ?? null;
            ?>
                <div class="forecast-day">
                    <strong class="forecast-day-name"><?= htmlspecialchars($dayLabel, ENT_QUOTES) ?></strong>
                    <span class="forecast-icon" role="img" aria-label="<?= htmlspecialchars($condition['label'], ENT_QUOTES) ?>"><?= $condition['icon'] ?></span>
                    <strong class="forecast-temperature">
                        <?= $high !== null ? number_format((float)$high, 0) . '°' : '—' ?>
                        <?php if ($low !== null): ?>
                            <span class="forecast-low">/ <?= number_format((float)$low, 0) ?>°</span>
                        <?php endif; ?>
                    </strong>
                    <?php if ($rainProbability > 0): ?>
                        <span class="forecast-rain">Rain <?= $rainProbability ?>%</span>
                    <?php else: ?>
                        <span class="forecast-rain forecast-rain-empty" aria-hidden="true">&nbsp;</span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="card weather-change-card" aria-labelledby="weatherChangeTitle">
        <div class="section-heading">
            <div>
                <h2 id="weatherChangeTitle">Weather change</h2>
                <p class="muted">Based on GaugeIQ's recent pressure, temperature, humidity and wind readings.</p>
            </div>
            <div class="weather-change-score" id="weatherChangeScore" aria-label="Weather change score">—</div></div>
        </div>
        <div class="weather-change-summary" id="weatherChangeSummary">Analysing recent conditions…</div>
        <div class="weather-change-reasons" id="weatherChangeReasons" aria-live="polite"></div>
        <p class="weather-change-note">This is a change indicator, not a precipitation forecast. More readings make the indicator more reliable.</p>
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
<script src="js/app.js?v=20261006-compass"></script>
</body>
</html>
