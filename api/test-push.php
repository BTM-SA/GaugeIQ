<?php
declare(strict_types=1);

$config = require __DIR__ . '/../config/local.php';
require __DIR__ . '/../app/Database.php';
require __DIR__ . '/../app/PushService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

$db = new Database($config);

try {
    $push = new PushService($config, $db->pdo());
    $sent = $push->send(
        'GaugeIQ test alert',
        'Push notifications are working on this device.'
    );

    echo json_encode(['ok' => true, 'sent' => $sent]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
