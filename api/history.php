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

    $stmt = $db->pdo()->prepare(
        'SELECT pressure_hpa, humidity_percent, wind_speed_kmh,
                wind_direction_degrees, observed_at
         FROM gaugeiq_pressure_readings
         WHERE observed_at >= ?
         ORDER BY observed_at ASC'
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
