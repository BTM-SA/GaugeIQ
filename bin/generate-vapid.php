<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Minishlink\WebPush\VAPID;

$keys = VAPID::createVapidKeys();

echo "GaugeIQ VAPID credentials
";
echo "=========================
";
echo "public_key=" . $keys['publicKey'] . "
";
echo "private_key=" . $keys['privateKey'] . "
";
