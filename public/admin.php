<?php
declare(strict_types=1);
require __DIR__ . '/../app/Database.php';
require __DIR__ . '/../app/Schema.php';
require __DIR__ . '/../app/AdminAuth.php';
$config = require __DIR__ . '/../config/local.php';
$db = new Database($config);
migrateDatabase($db->pdo());
AdminAuth::requireLogin();
$csrf = AdminAuth::csrfToken();
$updated = trim((string)($_GET['updated'] ?? ''));
$updateError = isset($_GET['update_error']);
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#111827"><link rel="stylesheet" href="css/app.css"><title>GaugeIQ Admin</title></head>
<body><main class="shell"><header><div><p class="eyebrow">GAUGЕIQ ADMIN</p><h1>Administration</h1><p class="muted">Signed in as <?= htmlspecialchars(AdminAuth::username(), ENT_QUOTES) ?></p></div><a class="secondary button-link" href="logout.php">Sign out</a></header>
<?php if ($updated !== ''): ?><section class="card success"><strong>GaugeIQ was updated to <?= htmlspecialchars($updated, ENT_QUOTES) ?>.</strong><p>Refresh the dashboard to verify the new release.</p></section><?php endif; ?>
<?php if ($updateError): ?><section class="card error"><strong>GaugeIQ could not complete the update.</strong><p>The detailed error was written to the server log.</p></section><?php endif; ?>
<section class="card"><h2>Updates</h2><p id="adminUpdateStatus" class="muted">Checking for the latest release…</p>
<div id="adminUpdateDetails" hidden><p><strong id="adminLatestVersion"></strong></p>
<form method="post" action="admin-update.php" onsubmit="return confirm('Install this verified GaugeIQ release now?');">
<input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
<input type="hidden" name="version" id="adminUpdateVersion">
<input type="hidden" name="package_url" id="adminPackageUrl">
<input type="hidden" name="checksum_url" id="adminChecksumUrl">
<button type="submit">Install update</button></form></div>
<p><a class="secondary button-link" href="./">Back to GaugeIQ</a></p></section>
<script>
(async()=>{try{const r=await fetch('api/update-check.php',{cache:'no-store'});const d=await r.json();const s=document.getElementById('adminUpdateStatus');if(!r.ok)throw 0;if(!d.update_available){s.textContent='GaugeIQ is up to date (v'+d.current_version+').';return}if(!d.package_url||!d.checksum_url){s.textContent='An update was found, but its release package or checksum is missing.';return}document.getElementById('adminLatestVersion').textContent='Version '+d.latest_version+' is available.';document.getElementById('adminUpdateVersion').value=d.latest_version;document.getElementById('adminPackageUrl').value=d.package_url;document.getElementById('adminChecksumUrl').value=d.checksum_url;document.getElementById('adminUpdateDetails').hidden=false;s.textContent='';}catch(e){document.getElementById('adminUpdateStatus').textContent='Unable to check for GaugeIQ updates.';}})();
</script></main></body></html>
