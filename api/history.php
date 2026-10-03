<?php
declare(strict_types=1);

$config = require __DIR__ . '/../config/local.php';
require __DIR__ . '/../app/Database.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $db = new Database($config);
    $hours = min(168, max(1, (int)($_GET['hours'] ?? 24)));
    $since = gmdate('c', time() - ($hours * 3600));

    // Filter by created_at, which is always stored in UTC by GaugeIQ.
    // observed_at comes from the weather provider's configured timezone and
    // therefore should not be used for the UTC history window.
    $stmt = $db->pdo()->prepare(
        'SELECT temperature_c, dew_point_c, pressure_hpa, humidity_percent, wind_speed_kmh,
                wind_direction_degrees, observed_at, created_at
         FROM gaugeiq_pressure_readings
         WHERE created_at >= ?
         ORDER BY created_at ASC'
    );
    $stmt->execute([$since]);

    echo json_encode([
        'hours' => $hours,
        'readings' => $stmt->fetchAll(),
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Unable to load GaugeIQ history.']);
}
