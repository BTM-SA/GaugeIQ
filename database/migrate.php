<?php
declare(strict_types=1);

$config = require __DIR__ . '/../config/local.php';

date_default_timezone_set($config['app']['timezone']);

$path = $config['database']['path'];
$dir = dirname($path);
if (!is_dir($dir)) {
    mkdir($dir, 0750, true);
}

$db = new PDO('sqlite:' . $path, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS pressure_readings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    pressure_hpa REAL NOT NULL,
    observed_at TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS push_subscriptions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    endpoint TEXT NOT NULL UNIQUE,
    subscription_json TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
SQL);

echo "GaugeIQ database ready.\n";
