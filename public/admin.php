<?php
declare(strict_types=1);
require __DIR__ . '/../app/Database.php';
require __DIR__ . '/../app/Schema.php';
require __DIR__ . '/../app/AdminAuth.php';
$config = require __DIR__ . '/../config/local.php';
$db = new Database($config);
migrateDatabase($db->pdo());
AdminAuth::requireLogin();
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#111827"><link rel="stylesheet" href="css/app.css"><title>GaugeIQ Admin</title></head>
<body><main class="shell"><header><div><p class="eyebrow">GAUGЕIQ ADMIN</p><h1>Administration</h1><p class="muted">Signed in as <?= htmlspecialchars(AdminAuth::username(), ENT_QUOTES) ?></p></div><a class="secondary button-link" href="logout.php">Sign out</a></header>
<section class="card"><h2>Updates</h2><p class="muted">Protected update controls will appear here once package verification and replacement are enabled.</p><a class="secondary button-link" href="./">Back to GaugeIQ</a></section></main></body></html>
