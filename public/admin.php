<?php
declare(strict_types=1);

require __DIR__ . '/../app/Database.php';
require __DIR__ . '/../app/Schema.php';
require __DIR__ . '/../app/AdminAuth.php';
require __DIR__ . '/../app/AlertRuleService.php';

$config = require __DIR__ . '/../config/local.php';
$db = new Database($config);
$pdo = $db->pdo();
migrateDatabase($pdo);
AdminAuth::requireLogin();
$csrf = AdminAuth::csrfToken();
$rules = new AlertRuleService($pdo);

$updated = trim((string)($_GET['updated'] ?? ''));
$updateError = isset($_GET['update_error']);
$notice = '';
$errors = [];

function h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function alertLookback(PDO $pdo): int {
    $stmt = $pdo->prepare("SELECT value FROM gaugeiq_settings WHERE key = 'alert_lookback_hours'");
    $stmt->execute();
    $value = $stmt->fetchColumn();
    return $value === false ? 3 : max(1, min(168, (int)$value));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!AdminAuth::verifyCsrf((string)($_POST['csrf'] ?? ''))) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        try {
            $action = (string)($_POST['action'] ?? '');

            if ($action === 'save_lookback') {
                $hours = max(1, min(168, (int)($_POST['lookback_hours'] ?? 3)));
                $stmt = $pdo->prepare("INSERT INTO gaugeiq_settings (`key`, `value`) VALUES ('alert_lookback_hours', ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
                if ((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                    $stmt = $pdo->prepare("INSERT INTO gaugeiq_settings (key, value) VALUES ('alert_lookback_hours', ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value");
                }
                $stmt->execute([(string)$hours]);
                $notice = 'Alert comparison window saved.';
            } elseif ($action === 'delete') {
                $rules->delete((int)$_POST['id']);
                $notice = 'Alert deleted.';
            } elseif ($action === 'toggle') {
                $id = (int)$_POST['id'];
                $rule = null;
                foreach ($rules->all() as $candidate) {
                    if ((int)$candidate['id'] === $id) {
                        $rule = $candidate;
                        break;
                    }
                }
                if (!$rule) {
                    throw new RuntimeException('Alert not found.');
                }
                $rules->update(
                    $id,
                    (string)$rule['name'],
                    (string)$rule['metric'],
                    (string)$rule['condition_type'],
                    json_decode((string)$rule['configuration_json'], true, 512, JSON_THROW_ON_ERROR),
                    !(bool)$rule['enabled'],
                    (int)$rule['cooldown_minutes']
                );
                $notice = 'Alert status updated.';
            } elseif ($action === 'create') {
                $metric = (string)$_POST['metric'];
                $type = (string)$_POST['condition_type'];
                $configuration = [];

                if (in_array($metric, ['pressure', 'humidity', 'wind_speed'], true)) {
                    $configuration['value'] = (float)$_POST['value'];
                } elseif ($metric === 'wind_direction') {
                    $configuration['degrees'] = (float)$_POST['degrees'];
                } elseif ($metric === 'wind') {
                    $configuration = [
                        'speed_min' => (float)$_POST['speed_min'],
                        'direction_from' => (float)$_POST['direction_from'],
                        'direction_to' => (float)$_POST['direction_to'],
                    ];
                }

                $rules->create(
                    (string)$_POST['name'],
                    $metric,
                    $type,
                    $configuration,
                    (int)$_POST['cooldown_minutes']
                );
                $notice = 'Alert created.';
            }
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$lookbackHours = alertLookback($pdo);
$allRules = $rules->all();
$enabledRuleCount = count(array_filter($allRules, static fn(array $rule): bool => (bool)$rule['enabled']));
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#111827">
<link rel="stylesheet" href="css/app.css">
<link rel="stylesheet" href="css/alerts.css">
<title>GaugeIQ Admin</title>
</head>
<body>
<main class="shell admin-shell">
<header class="admin-header">
    <div>
        <p class="eyebrow">GAUGЕIQ ADMIN</p>
        <h1>Administration</h1>
        <p class="muted">Signed in as <?= h(AdminAuth::username()) ?></p>
    </div>
    <a class="secondary button-link" href="logout.php">Sign out</a>
</header>

<?php if ($updated !== ''): ?>
<section class="card success"><strong>GaugeIQ was updated to <?= h($updated) ?>.</strong><p>Return to the dashboard to verify the new release.</p></section>
<?php endif; ?>
<?php if ($updateError): ?>
<section class="card error"><strong>GaugeIQ could not complete the update.</strong><p>The detailed error was written to the server log.</p></section>
<?php endif; ?>
<?php if ($notice): ?><div class="notice"><?= h($notice) ?></div><?php endif; ?>
<?php if ($errors): ?><div class="error"><?php foreach ($errors as $error): ?><div><?= h($error) ?></div><?php endforeach; ?></div><?php endif; ?>

<section class="admin-hero card">
    <div>
        <p class="eyebrow">WEATHER ALERTS</p>
        <h2>Alerts</h2>
        <p class="muted">Control what GaugeIQ watches and how far back it compares each new reading.</p>
    </div>
    <div class="alert-summary">
        <strong><?= $enabledRuleCount ?></strong>
        <span>active rule<?= $enabledRuleCount === 1 ? '' : 's' ?></span>
    </div>
</section>

<section class="card alert-window-card">
    <div class="section-heading">
        <div>
            <h2>Comparison window</h2>
            <p class="muted">The alert engine compares each new reading with the most recent reading at least this far back. This is independent of your cron interval.</p>
        </div>
        <span class="status-pill status-good">● Independent of cron</span>
    </div>
    <form method="post" class="lookback-form">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="save_lookback">
        <label for="lookback_hours">Compare against a reading from</label>
        <div class="lookback-controls">
            <input id="lookback_hours" name="lookback_hours" type="number" min="1" max="168" step="1" value="<?= $lookbackHours ?>">
            <span>hours ago</span>
            <button type="submit">Save window</button>
        </div>
        <p class="alert-help muted">For example, with a 60-minute cron and a 3-hour window, a new reading is compared with the reading closest to 3 hours earlier. Range: 1–168 hours.</p>
    </form>
</section>

<section class="card">
    <div class="section-heading">
        <div>
            <h2>Create an alert</h2>
            <p class="muted">Set a condition and GaugeIQ will evaluate it whenever the scheduled monitor records a new reading.</p>
        </div>
    </div>
    <form method="post" class="alert-form">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="create">
        <div class="alert-grid">
            <div class="full">
                <label for="name">Alert name</label>
                <input id="name" name="name" placeholder="Pressure change" required>
            </div>
            <div>
                <label for="metric">Monitor</label>
                <select id="metric" name="metric">
                    <option value="pressure">Air pressure</option>
                    <option value="humidity">Humidity</option>
                    <option value="wind_speed">Wind speed</option>
                    <option value="wind_direction">Wind direction</option>
                    <option value="wind">Wind speed + direction</option>
                </select>
            </div>
            <div>
                <label for="condition_type">Condition</label>
                <select id="condition_type" name="condition_type">
                    <option value="change">Changes by</option>
                    <option value="above">Rises above</option>
                    <option value="below">Falls below</option>
                    <option value="specific">Specific direction</option>
                    <option value="speed_and_direction">Speed AND direction</option>
                </select>
            </div>
            <div id="valueField">
                <label for="value">Value</label>
                <input id="value" name="value" type="number" step="0.1" value="3">
            </div>
            <div id="degreesField" class="full" hidden>
                <label>Specific wind direction</label>
                <div class="compass-grid" role="group" aria-label="Specific wind direction">
                    <button type="button" class="compass-choice" data-degrees="0">N</button>
                    <button type="button" class="compass-choice" data-degrees="45">NE</button>
                    <button type="button" class="compass-choice" data-degrees="90">E</button>
                    <button type="button" class="compass-choice" data-degrees="135">SE</button>
                    <button type="button" class="compass-choice" data-degrees="180">S</button>
                    <button type="button" class="compass-choice" data-degrees="225">SW</button>
                    <button type="button" class="compass-choice" data-degrees="270">W</button>
                    <button type="button" class="compass-choice" data-degrees="315">NW</button>
                </div>
                <input id="degrees" name="degrees" type="hidden" value="0">
                <p class="alert-help muted">GaugeIQ stores the selected compass direction as degrees.</p>
            </div>
            <div id="speedField" hidden>
                <label for="speed_min">Minimum wind speed (km/h)</label>
                <input id="speed_min" name="speed_min" type="number" min="0" step="0.1" value="40">
            </div>
            <div id="fromField" class="full" hidden>
                <label>Wind direction range</label>
                <div class="direction-range">
                    <select id="direction_from" name="direction_from">
                        <option value="315">NW</option><option value="0">N</option><option value="45">NE</option><option value="90">E</option><option value="135">SE</option><option value="180">S</option><option value="225">SW</option><option value="270">W</option>
                    </select>
                    <span>→</span>
                    <select id="direction_to" name="direction_to">
                        <option value="0">N</option><option value="45">NE</option><option value="90">E</option><option value="135">SE</option><option value="180">S</option><option value="225">SW</option><option value="270">W</option><option value="315">NW</option>
                    </select>
                </div>
                <p class="alert-help muted">Ranges may cross north, for example NW → N.</p>
            </div>
            <div>
                <label for="cooldown_minutes">Cooldown</label>
                <input id="cooldown_minutes" name="cooldown_minutes" type="number" min="0" value="60">
                <p class="alert-help muted">Minimum time between notifications for this rule.</p>
            </div>
        </div>
        <button type="submit">Add alert</button>
    </form>
</section>

<section class="alerts-list-section">
    <div class="section-heading">
        <div>
            <h2>Saved alerts</h2>
            <p class="muted"><?= count($allRules) ?> configured rule<?= count($allRules) === 1 ? '' : 's' ?>.</p>
        </div>
    </div>
    <?php if (!$allRules): ?>
        <div class="card alert-empty"><div class="alert-empty-icon">✓</div><h3>No alerts configured</h3><p class="muted">Create your first weather alert above. GaugeIQ will evaluate it during the next scheduled check.</p></div>
    <?php endif; ?>

    <?php foreach ($allRules as $rule): ?>
        <?php $ruleConfig = json_decode((string)$rule['configuration_json'], true) ?: []; ?>
        <article class="card alert-card">
            <div class="alert-card-main">
                <div class="alert-card-title">
                    <span class="alert-rule-dot <?= (int)$rule['enabled'] ? 'enabled' : '' ?>"></span>
                    <h3><?= h((string)$rule['name']) ?></h3>
                    <span class="alert-state <?= (int)$rule['enabled'] ? 'on' : 'off' ?>"><?= (int)$rule['enabled'] ? 'Enabled' : 'Disabled' ?></span>
                </div>
                <div class="alert-meta"><?= h(ucwords(str_replace('_', ' ', (string)$rule['metric']))) ?> · <?= h(ucwords(str_replace('_', ' ', (string)$rule['condition_type']))) ?> · cooldown <?= (int)$rule['cooldown_minutes'] ?> min</div>
                <?php if (!empty($ruleConfig)): ?>
                    <div class="alert-config"><?= h(json_encode($ruleConfig, JSON_UNESCAPED_SLASHES)) ?></div>
                <?php endif; ?>
            </div>
            <div class="alert-actions">
                <form method="post">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="id" value="<?= (int)$rule['id'] ?>">
                    <button type="submit" class="secondary"><?= (int)$rule['enabled'] ? 'Disable' : 'Enable' ?></button>
                </form>
                <form method="post" onsubmit="return confirm('Delete this alert?')">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$rule['id'] ?>">
                    <button type="submit" class="danger-button">Delete</button>
                </form>
            </div>
        </article>
    <?php endforeach; ?>
</section>

<section class="card admin-updates">
    <div class="section-heading">
        <div><h2>Updates</h2><p id="adminUpdateStatus" class="muted">Checking for the latest release…</p></div>
    </div>
    <div id="adminUpdateDetails" hidden>
        <p><strong id="adminLatestVersion"></strong></p>
        <form method="post" action="admin-update.php">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="version" id="adminUpdateVersion">
            <input type="hidden" name="package_url" id="adminPackageUrl">
            <input type="hidden" name="checksum_url" id="adminChecksumUrl">
            <button type="submit">Install update</button>
        </form>
    </div>
    <a class="secondary button-link" href="./">Back to GaugeIQ</a>
</section>

<script src="js/admin.js" defer></script>
<script src="js/alerts.js" defer></script>
</main>
</body>
</html>