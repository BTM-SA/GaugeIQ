<?php
declare(strict_types=1);

$config = require __DIR__ . '/../config/local.php';
require __DIR__ . '/../app/Database.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

$input = json_decode((string)file_get_contents('php://input'), true);

if (!is_array($input) || !isset($input['endpoint']) || !is_string($input['endpoint'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid push subscription.']);
    exit;
}

$endpoint = trim($input['endpoint']);
$keys = $input['keys'] ?? null;

if ($endpoint === '' || !filter_var($endpoint, FILTER_VALIDATE_URL) || !is_array($keys)
    || !is_string($keys['p256dh'] ?? null) || !is_string($keys['auth'] ?? null)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid push subscription.']);
    exit;
}

$subscription = [
    'endpoint' => $endpoint,
    'expirationTime' => $input['expirationTime'] ?? null,
    'keys' => [
        'p256dh' => $keys['p256dh'],
        'auth' => $keys['auth'],
    ],
];

$db = new Database($config);
$pdo = $db->pdo();
$now = gmdate('c');

$stmt = $pdo->prepare(
    'INSERT INTO push_subscriptions (endpoint, subscription_json, created_at, updated_at)
     VALUES (?, ?, ?, ?)
     ON CONFLICT(endpoint) DO UPDATE SET
        subscription_json = excluded.subscription_json,
        updated_at = excluded.updated_at'
);
$stmt->execute([
    $endpoint,
    json_encode($subscription, JSON_THROW_ON_ERROR),
    $now,
    $now,
]);

echo json_encode(['ok' => true]);
