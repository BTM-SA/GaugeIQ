<?php
declare(strict_types=1);

$config = require __DIR__ . '/../config/local.php';
require __DIR__ . '/../app/Database.php';
require __DIR__ . '/../app/AdminAuth.php';

AdminAuth::startSession();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$db = new Database($config);
$pdo = $db->pdo();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $rows = $pdo->query(
        'SELECT id, endpoint, user_agent, created_at, updated_at, last_seen_at, last_push_at, last_push_status, last_push_error FROM gaugeiq_push_subscriptions ORDER BY id DESC'
    )->fetchAll();

    $devices = [];
    foreach ($rows as $row) {
        $ua = (string)($row['user_agent'] ?? '');
        $device = 'Unknown device';
        $browser = 'Unknown browser';
        if (preg_match('/iPhone/i', $ua)) $device = 'iPhone';
        elseif (preg_match('/iPad/i', $ua)) $device = 'iPad';
        elseif (preg_match('/Macintosh/i', $ua)) $device = 'Mac';
        elseif (preg_match('/Android/i', $ua)) $device = 'Android device';
        elseif (preg_match('/Windows/i', $ua)) $device = 'Windows PC';

        if (preg_match('/CriOS\/([\d.]+)/i', $ua, $m)) $browser = 'Chrome ' . $m[1];
        elseif (preg_match('/FxiOS\/([\d.]+)/i', $ua, $m)) $browser = 'Firefox ' . $m[1];
        elseif (preg_match('/EdgiOS\/([\d.]+)/i', $ua, $m)) $browser = 'Edge ' . $m[1];
        elseif (preg_match('/Version\/([\d.]+).*Safari/i', $ua, $m)) $browser = 'Safari ' . $m[1];
        elseif (preg_match('/Chrome\/([\d.]+)/i', $ua, $m)) $browser = 'Chrome ' . $m[1];
        elseif (preg_match('/Firefox\/([\d.]+)/i', $ua, $m)) $browser = 'Firefox ' . $m[1];

        $devices[] = [
            'id' => (int)$row['id'],
            'endpoint_hash' => hash('sha256', (string)$row['endpoint']),
            'device' => $device,
            'browser' => $browser,
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
            'last_seen_at' => $row['last_seen_at'],
            'last_push_at' => $row['last_push_at'],
            'last_push_status' => $row['last_push_status'],
            'last_push_error' => $row['last_push_error'],
        ];
    }

    echo json_encode(['devices' => $devices]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !AdminAuth::verifyCsrf((string)($_POST['csrf'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid request.']);
    exit;
}

$id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid device.']);
    exit;
}

$stmt = $pdo->prepare('DELETE FROM gaugeiq_push_subscriptions WHERE id = ?');
$stmt->execute([(int)$id]);

echo json_encode(['ok' => true]);
