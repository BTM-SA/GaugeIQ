<?php
declare(strict_types=1);

require __DIR__ . '/../app/Database.php';
require __DIR__ . '/../app/Schema.php';
require __DIR__ . '/../app/AdminAuth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    respond(405, ['success' => false, 'error' => 'POST is required.']);
}

$configPath = __DIR__ . '/../config/local.php';
if (!is_file($configPath)) {
    respond(503, ['success' => false, 'error' => 'GaugeIQ is not installed.']);
}
$config = require $configPath;
AdminAuth::startSession();

$raw = file_get_contents('php://input');
$data = json_decode($raw === false ? '' : $raw, true);
if (!is_array($data) || !AdminAuth::verifyCsrf((string)($data['csrf'] ?? ''))) {
    respond(403, ['success' => false, 'error' => 'Your session expired. Reload GaugeIQ and try again.']);
}

$latitude = filter_var($data['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
$longitude = filter_var($data['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
$name = trim((string)($data['name'] ?? ''));
$timezone = trim((string)($data['timezone'] ?? 'auto'));

if ($latitude === false || $latitude < -90 || $latitude > 90 ||
    $longitude === false || $longitude < -180 || $longitude > 180) {
    respond(422, ['success' => false, 'error' => 'The selected location has invalid coordinates.']);
}
if ($name === '') {
    $name = 'Saved location';
}
$name = function_exists('mb_substr') ? mb_substr($name, 0, 120) : substr($name, 0, 120);
if ($timezone !== 'auto') {
    try {
        new DateTimeZone($timezone);
    } catch (Throwable) {
        respond(422, ['success' => false, 'error' => 'The selected location has an invalid timezone.']);
    }
}

try {
    $db = new Database($config);
    $pdo = $db->pdo();
    migrateDatabase($pdo);
    $existingLocation = $pdo->query(
        "SELECT `key`, `value` FROM gaugeiq_settings
         WHERE `key` IN ('active_location_latitude', 'active_location_longitude')"
    )->fetchAll();
    $existingCoordinates = [];
    foreach ($existingLocation as $setting) {
        $existingCoordinates[(string)$setting['key']] = (float)$setting['value'];
    }
    $locationChanged = !isset($existingCoordinates['active_location_latitude'], $existingCoordinates['active_location_longitude'])
        || abs($existingCoordinates['active_location_latitude'] - (float)$latitude) > 0.000001
        || abs($existingCoordinates['active_location_longitude'] - (float)$longitude) > 0.000001;

    $values = [
        'active_location_latitude' => (string)$latitude,
        'active_location_longitude' => (string)$longitude,
        'active_location_name' => $name,
        'active_location_timezone' => $timezone,
    ];
    if ($locationChanged) {
        $values['active_location_changed_at'] = gmdate('c');
    }
    $upsert = $pdo->prepare('SELECT 1 FROM gaugeiq_settings WHERE `key` = ?');
    $insert = $pdo->prepare('INSERT INTO gaugeiq_settings (`key`, `value`) VALUES (?, ?)');
    $update = $pdo->prepare('UPDATE gaugeiq_settings SET `value` = ? WHERE `key` = ?');
    $pdo->beginTransaction();
    foreach ($values as $key => $value) {
        $upsert->execute([$key]);
        if ($upsert->fetchColumn() === false) {
            $insert->execute([$key, $value]);
        } else {
            $update->execute([$value, $key]);
        }
    }
    $pdo->commit();
    respond(200, ['success' => true, 'location' => ['name' => $name, 'latitude' => (float)$latitude, 'longitude' => (float)$longitude, 'timezone' => $timezone]]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('GaugeIQ dashboard location sync failed: ' . $e->getMessage());
    respond(500, ['success' => false, 'error' => 'Unable to save the active location on the server.']);
}
