<?php
declare(strict_types=1);

require __DIR__ . '/../app/Database.php';
require __DIR__ . '/../app/Schema.php';
require __DIR__ . '/../app/AdminAuth.php';
require __DIR__ . '/../app/GaugeIQUpdater.php';
require __DIR__ . '/../app/Version.php';

$config = require __DIR__ . '/../config/local.php';
$db = new Database($config);
migrateDatabase($db->pdo());
AdminAuth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !AdminAuth::verifyCsrf((string)($_POST['csrf'] ?? ''))) {
    http_response_code(403);
    exit('Invalid update request.');
}

$version = trim((string)($_POST['version'] ?? ''));
$packageUrl = trim((string)($_POST['package_url'] ?? ''));
$checksumUrl = trim((string)($_POST['checksum_url'] ?? ''));

try {
    $updater = new GaugeIQUpdater(dirname(__DIR__));
    $result = $updater->installLatest($version, $packageUrl, $checksumUrl);
    header('Location: admin.php?updated=' . rawurlencode($result['version']));
    exit;
} catch (Throwable $e) {
    error_log('GaugeIQ update failed: ' . $e->getMessage());
    header('Location: admin.php?update_error=1');
    exit;
}
