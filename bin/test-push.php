<?php
declare(strict_types=1);

$config = require __DIR__ . '/../config/local.php';
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../app/Database.php';
require __DIR__ . '/../app/PushService.php';

$db = new Database($config);
$push = new PushService($config, $db->pdo());

$sent = $push->send(
    'GaugeIQ test alert',
    'Push notifications are working on this device.'
);

printf("GaugeIQ test alert sent to %d device(s).\n", $sent);
