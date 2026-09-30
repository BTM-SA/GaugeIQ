<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$configDir = $root . '/config';
$configPath = $configDir . '/local.php';
$lockPath = $configDir . '/installed.lock';

require_once $root . '/app/Database.php';
require_once $root . '/app/Schema.php';

$autoload = $root . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

header('Cache-Control: no-store');

if (is_file($lockPath)) {
    http_response_code(404);
    exit('GaugeIQ is already installed.');
}

$errors = [];
$success = false;
$defaults = [
    'location_name' => 'My location',
    'latitude' => '',
    'longitude' => '',
    'threshold_hpa' => '3.0',
    'humidity_enabled' => '1',
    'wind_enabled' => '1',
    'database_driver' => 'sqlite',
    'database_host' => '127.0.0.1',
    'database_port' => '3306',
    'database_name' => '',
    'database_username' => '',
    'database_password' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($defaults as $key => $default) {
        $defaults[$key] = trim((string)($_POST[$key] ?? $default));
    }

    if (!filter_var($defaults['latitude'], FILTER_VALIDATE_FLOAT) ||
        (float)$defaults['latitude'] < -90 || (float)$defaults['latitude'] > 90) {
        $errors[] = 'Enter a valid latitude.';
    }

    if (!filter_var($defaults['longitude'], FILTER_VALIDATE_FLOAT) ||
        (float)$defaults['longitude'] < -180 || (float)$defaults['longitude'] > 180) {
        $errors[] = 'Enter a valid longitude.';
    }

    $threshold = filter_var($defaults['threshold_hpa'], FILTER_VALIDATE_FLOAT);
    if ($threshold === false || $threshold <= 0) {
        $errors[] = 'Enter a pressure alert threshold greater than zero.';
    }

    if (!in_array($defaults['database_driver'], ['sqlite', 'mysql'], true)) {
        $errors[] = 'Choose a valid database type.';
    }

    if ($defaults['database_driver'] === 'mysql') {
        foreach (['database_name', 'database_username'] as $field) {
            if ($defaults[$field] === '') {
                $errors[] = 'MySQL/MariaDB database name and username are required.';
            }
        }
    }

    if (!$errors) {
        try {
            if (!class_exists(\Minishlink\WebPush\VAPID::class)) {
                throw new RuntimeException('GaugeIQ Web Push dependencies are missing. The installation package must include vendor/ or Composer dependencies must be installed first.');
            }

            $vapid = \Minishlink\WebPush\VAPID::createVapidKeys();

            $config = [
                'app' => [
                    'name' => 'GaugeIQ',
                    'base_url' => rtrim(
                        'https://' . ($_SERVER['HTTP_HOST'] ?? '') .
                        rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/'),
                        '/'
                    ),
                    'timezone' => 'Africa/Johannesburg',
                ],
                'database' => [
                    'driver' => $defaults['database_driver'],
                    'path' => $root . '/storage/gaugeiq.sqlite',
                    'host' => $defaults['database_host'],
                    'port' => $defaults['database_port'],
                    'name' => $defaults['database_name'],
                    'username' => $defaults['database_username'],
                    'password' => $defaults['database_password'],
                    'charset' => 'utf8mb4',
                ],
                'pressure' => [
                    'latitude' => (float)$defaults['latitude'],
                    'longitude' => (float)$defaults['longitude'],
                    'location_name' => $defaults['location_name'] ?: 'My location',
                    'threshold_hpa' => (float)$threshold,
                    'check_interval_minutes' => 15,
                ],
                'humidity' => [
                    'enabled' => $defaults['humidity_enabled'] === '1',
                    'mode' => 'change',
                    'threshold_percent' => 10.0,
                ],
                'wind' => [
                    'enabled' => $defaults['wind_enabled'] === '1',
                    'speed_mode' => 'above',
                    'speed_threshold_kmh' => 40.0,
                    'direction_change_degrees' => 45.0,
                    'specific_directions' => [],
                ],
                'push' => [
                    'subject' => '',
                    'public_key' => $vapid['publicKey'],
                    'private_key' => $vapid['privateKey'],
                ],
            ];

            $config['push']['subject'] = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

            if ($config['database']['driver'] === 'sqlite') {
                unset(
                    $config['database']['host'],
                    $config['database']['port'],
                    $config['database']['name'],
                    $config['database']['username'],
                    $config['database']['password'],
                    $config['database']['charset']
                );
            }

            $db = new Database($config);
            migrateDatabase($db->pdo());

            if (!is_dir($configDir) && !mkdir($configDir, 0750, true) && !is_dir($configDir)) {
                throw new RuntimeException('Unable to create the configuration directory.');
            }

            $php = "<?php

declare(strict_types=1);

return " . var_export($config, true) . ";
";
            if (is_file($configPath)) {
                throw new RuntimeException('config/local.php already exists. The installer will not overwrite it.');
            }

            if (file_put_contents($configPath, $php, LOCK_EX) === false) {
                throw new RuntimeException('GaugeIQ could not write config/local.php.');
            }

            $lockContents = "GaugeIQ installed " . gmdate('c') . "
";
            if (file_put_contents($lockPath, $lockContents, LOCK_EX) === false) {
                @unlink($configPath);
                throw new RuntimeException('GaugeIQ could not lock the installer.');
            }

            $success = true;
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#111827">
<title>Set up GaugeIQ</title>
<link rel="stylesheet" href="install.css">
</head>
<body>
<main class="shell">
    <p class="step">GAUGЕIQ SETUP</p>
    <h1>Welcome to GaugeIQ</h1>
    <p class="muted">Let's get your weather monitoring and notifications ready.</p>

    <?php if ($success): ?>
        <section class="card success">
            <strong>GaugeIQ is installed.</strong>
            <p>Your database and configuration are ready.</p>
            <p>Open the GaugeIQ home page and enable notifications on your iPhone.</p>
            <button type="button" onclick="location.href='../'">Open GaugeIQ</button>
        </section>
    <?php else: ?>
        <?php if ($errors): ?>
            <section class="card error">
                <?php foreach ($errors as $error): ?>
                    <div><?= htmlspecialchars($error, ENT_QUOTES) ?></div>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>

        <form method="post" class="card">
            <p class="step">1 · LOCATION</p>
            <label for="location_name">Location name</label>
            <input id="location_name" name="location_name" value="<?= htmlspecialchars($defaults['location_name'], ENT_QUOTES) ?>" placeholder="My home">

            <div class="grid">
                <div>
                    <label for="latitude">Latitude</label>
                    <input id="latitude" name="latitude" value="<?= htmlspecialchars($defaults['latitude'], ENT_QUOTES) ?>" inputmode="decimal" required>
                </div>
                <div>
                    <label for="longitude">Longitude</label>
                    <input id="longitude" name="longitude" value="<?= htmlspecialchars($defaults['longitude'], ENT_QUOTES) ?>" inputmode="decimal" required>
                </div>
            </div>

            <button type="button" class="secondary" id="locationButton">Use my current location</button>

            <p class="step" style="margin-top:28px">2 · PRESSURE</p>
            <label for="threshold_hpa">Alert when pressure changes by</label>
            <input id="threshold_hpa" name="threshold_hpa" value="<?= htmlspecialchars($defaults['threshold_hpa'], ENT_QUOTES) ?>" type="number" step="0.1" min="0.1" required>
            <p class="muted">GaugeIQ will check pressure every 15 minutes.</p>

            <p class="step" style="margin-top:28px">3 · WEATHER MONITORING</p>
            <label class="choice"><input type="checkbox" name="humidity_enabled" value="1" <?= $defaults['humidity_enabled'] === '1' ? 'checked' : '' ?>> Monitor humidity</label>
            <label class="choice"><input type="checkbox" name="wind_enabled" value="1" <?= $defaults['wind_enabled'] === '1' ? 'checked' : '' ?>> Monitor wind speed and direction</label>

            <p class="step" style="margin-top:28px">4 · DATABASE</p>
            <label for="database_driver">Database</label>
            <select id="database_driver" name="database_driver">
                <option value="sqlite" <?= $defaults['database_driver'] === 'sqlite' ? 'selected' : '' ?>>SQLite — easiest</option>
                <option value="mysql" <?= $defaults['database_driver'] === 'mysql' ? 'selected' : '' ?>>Existing MySQL / MariaDB database</option>
            </select>

            <div id="mysqlFields" hidden>
                <label for="database_host">Database host</label>
                <input id="database_host" name="database_host" value="<?= htmlspecialchars($defaults['database_host'], ENT_QUOTES) ?>">
                <div class="grid">
                    <div>
                        <label for="database_port">Port</label>
                        <input id="database_port" name="database_port" value="<?= htmlspecialchars($defaults['database_port'], ENT_QUOTES) ?>">
                    </div>
                    <div>
                        <label for="database_name">Database name</label>
                        <input id="database_name" name="database_name" value="<?= htmlspecialchars($defaults['database_name'], ENT_QUOTES) ?>">
                    </div>
                </div>
                <label for="database_username">Username</label>
                <input id="database_username" name="database_username" value="<?= htmlspecialchars($defaults['database_username'], ENT_QUOTES) ?>">
                <label for="database_password">Password</label>
                <input id="database_password" name="database_password" type="password" value="<?= htmlspecialchars($defaults['database_password'], ENT_QUOTES) ?>">
            </div>

            <button type="submit">Install GaugeIQ</button>
        </form>
    <?php endif; ?>
</main>
<script src="install.js" defer></script>
</body>
</html>
