<?php
declare(strict_types=1);

require __DIR__ . '/../app/Database.php';
require __DIR__ . '/../app/Schema.php';
require __DIR__ . '/../app/AdminAuth.php';

$config = require __DIR__ . '/../config/local.php';
$db = new Database($config);
migrateDatabase($db->pdo());
AdminAuth::startSession();

if (AdminAuth::check()) {
    header('Location: admin.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!AdminAuth::verifyCsrf((string)($_POST['csrf'] ?? ''))) {
        $error = 'Your session expired. Please try again.';
    } elseif (AdminAuth::login($db->pdo(), trim((string)($_POST['username'] ?? '')), (string)($_POST['password'] ?? ''))) {
        header('Location: admin.php');
        exit;
    } else {
        $error = 'Invalid administrator credentials.';
    }
}

$csrf = AdminAuth::csrfToken();
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#111827"><link rel="stylesheet" href="css/app.css?v=20261010-admin-auth"><title>GaugeIQ Admin</title></head>
<body class="auth-page"><main class="shell auth-shell"><section class="card admin-auth" aria-labelledby="adminAuthTitle"><p class="eyebrow">GAUGЕIQ ADMIN</p><h1 id="adminAuthTitle">Administrator sign in</h1><p class="muted">Sign in to manage protected GaugeIQ administration features.</p>
<?php if ($error): ?><p class="monitor-warning"><?= htmlspecialchars($error, ENT_QUOTES) ?></p><?php endif; ?>
<form method="post" autocomplete="on"><input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>"><label>Username<input name="username" autocomplete="username" required autofocus></label><label>Password<input type="password" name="password" autocomplete="current-password" required></label><button class="secondary auth-submit" type="submit">Sign in</button></form>
<a class="secondary button-link admin-auth-back" href="./"><span aria-hidden="true">←</span> Back to GaugeIQ</a></section></main></body></html>
