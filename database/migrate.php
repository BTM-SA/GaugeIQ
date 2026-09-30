<?php
declare(strict_types=1);

$config = require __DIR__ . '/../config/local.php';
require __DIR__ . '/../app/Database.php';
require __DIR__ . '/../app/Schema.php';

date_default_timezone_set($config['app']['timezone']);

$db = new Database($config);
migrateDatabase($db->pdo());

echo "GaugeIQ database ready.\n";
