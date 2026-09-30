<?php
declare(strict_types=1);

$config = require __DIR__ . '/../config/local.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$publicKey = trim((string)$config['push']['public_key']);

if ($publicKey === '') {
    http_response_code(503);
    echo json_encode(['error' => 'Push notifications are not configured.']);
    exit;
}

echo json_encode(['publicKey' => $publicKey], JSON_UNESCAPED_SLASHES);
