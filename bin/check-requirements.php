<?php
declare(strict_types=1);

$configPath = __DIR__ . '/../config/local.php';

echo "GaugeIQ requirements check\n";
echo "=========================\n";

$required = [
    'pdo_sqlite' => extension_loaded('pdo_sqlite'),
    'curl' => extension_loaded('curl'),
    'mbstring' => extension_loaded('mbstring'),
    'openssl' => extension_loaded('openssl'),
];

foreach ($required as $name => $ok) {
    printf("%-12s %s\n", $name, $ok ? 'OK' : 'MISSING');
}

if (!is_file($configPath)) {
    echo "config/local.php MISSING\n";
    echo "Create it from config/config.example.php before running the migration.\n";
    exit(1);
}

$config = require $configPath;

$checks = [
    'database directory writable' => is_dir(dirname($config['database']['path']))
        ? is_writable(dirname($config['database']['path']))
        : is_writable(dirname($config['database']['path'])),
    'public base URL is HTTPS' => str_starts_with(
        strtolower((string)($config['app']['base_url'] ?? '')),
        'https://'
    ),
    'VAPID public key configured' => trim((string)($config['push']['public_key'] ?? '')) !== '',
    'VAPID private key configured' => trim((string)($config['push']['private_key'] ?? '')) !== '',
    'VAPID subject configured' => trim((string)($config['push']['subject'] ?? '')) !== '',
];

foreach ($checks as $name => $ok) {
    printf("%-30s %s\n", $name, $ok ? 'OK' : 'CHECK');
}

$failed = array_filter($required, static fn(bool $ok): bool => !$ok);
if ($failed) {
    exit(1);
}

echo "Requirements check complete.\n";
