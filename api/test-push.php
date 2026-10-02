<?php
declare(strict_types=1);

$config = require __DIR__ . '/../config/local.php';
require __DIR__ . '/../app/AdminAuth.php';
require __DIR__ . '/../app/Database.php';
require __DIR__ . '/../app/PushService.php';
require __DIR__ . '/../app/Schema.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

AdminAuth::startSession();

if (!AdminAuth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Admin login is required for the server push test.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

$csrf = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!AdminAuth::verifyCsrf($csrf)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid security token. Refresh GaugeIQ and try again.']);
    exit;
}

try {
    $db = new Database($config);
    $pdo = $db->pdo();
    migrateDatabase($pdo);

    $push = new PushService($config, $pdo);
    $sent = $push->send(
        'GaugeIQ server test',
        'This notification was sent through GaugeIQ\'s server-side Web Push service.'
    );

    echo json_encode([
        'ok' => true,
        'sent' => $sent,
    ]);
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode([
        'error' => 'Server push failed: ' . $e->getMessage(),
    ]);
}
