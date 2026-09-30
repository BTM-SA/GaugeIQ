<?php
declare(strict_types=1);
require __DIR__ . '/../app/AdminAuth.php';
AdminAuth::logout();
header('Location: login.php');
exit;
