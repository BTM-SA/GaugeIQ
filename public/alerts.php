<?php
declare(strict_types=1);

session_start();

$config = require __DIR__ . '/../config/local.php';
require __DIR__ . '/../app/Database.php';
require __DIR__ . '/../app/Schema.php';
require __DIR__ . '/../app/AlertRuleService.php';

$db = new Database($config);
$pdo = $db->pdo();
migrateDatabase($pdo);
$rules = new AlertRuleService($pdo);

if (empty($_SESSION['gaugeiq_csrf'])) {
    $_SESSION['gaugeiq_csrf'] = bin2hex(random_bytes(24));
}

$errors = [];
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['gaugeiq_csrf'], (string)($_POST['csrf'] ?? ''))) {
        $errors[] = 'Security validation failed. Please try again.';
    } else {
        try {
            $action = (string)($_POST['action'] ?? '');

            if ($action === 'delete') {
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

$allRules = $rules->all();
function h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#111827">
<title>GaugeIQ Alerts</title>
<link rel="manifest" href="manifest.json">
<link rel="stylesheet" href="css/app.css">
<link rel="stylesheet" href="css/alerts.css">

</head>
<body>
<main class="shell">
<a class="back" href="./">← GaugeIQ</a>
<header>
    <div>
        <p class="eyebrow">MONITORING</p>
        <h1>Alerts</h1>
        <p class="muted">Choose exactly what GaugeIQ should notify you about.</p>
    </div>
</header>

<?php if ($notice): ?><div class="notice"><?= h($notice) ?></div><?php endif; ?>
<?php if ($errors): ?><div class="error"><?php foreach ($errors as $error): ?><div><?= h($error) ?></div><?php endforeach; ?></div><?php endif; ?>

<section class="card">
    <h2>New alert</h2>
    <form method="post" class="alert-form">
        <input type="hidden" name="csrf" value="<?= h($_SESSION['gaugeiq_csrf']) ?>">
        <input type="hidden" name="action" value="create">

        <div class="alert-grid">
            <div class="full">
                <label for="name">Alert name</label>
                <input id="name" name="name" placeholder="Strong wind" required>
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
                <label for="cooldown_minutes">Cooldown (minutes)</label>
                <input id="cooldown_minutes" name="cooldown_minutes" type="number" min="0" value="60">
            </div>
        </div>

        <button type="submit">Add alert</button>
    </form>
</section>

<section>
    <h2>Your alerts</h2>
    <?php if (!$allRules): ?>
        <div class="card"><p class="muted">No custom alerts yet.</p></div>
    <?php endif; ?>

    <?php foreach ($allRules as $rule): ?>
        <?php $ruleConfig = json_decode((string)$rule['configuration_json'], true) ?: []; ?>
        <div class="card alert-card">
            <div>
                <strong><?= h((string)$rule['name']) ?></strong>
                <div class="alert-meta">
                    <?= h(ucwords(str_replace('_', ' ', (string)$rule['metric']))) ?> · <?= h(ucwords(str_replace('_', ' ', (string)$rule['condition_type']))) ?>
                    · cooldown <?= (int)$rule['cooldown_minutes'] ?> min
                </div>
                <?php if (!empty($ruleConfig)): ?>
                    <div class="alert-meta"><?= h(json_encode($ruleConfig, JSON_UNESCAPED_SLASHES)) ?></div>
                <?php endif; ?>
            </div>
            <div class="alert-actions">
                <form method="post">
                    <input type="hidden" name="csrf" value="<?= h($_SESSION['gaugeiq_csrf']) ?>">
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="id" value="<?= (int)$rule['id'] ?>">
                    <button type="submit"><?= (int)$rule['enabled'] ? 'Disable' : 'Enable' ?></button>
                </form>
                <form method="post" onsubmit="return confirm('Delete this alert?')">
                    <input type="hidden" name="csrf" value="<?= h($_SESSION['gaugeiq_csrf']) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$rule['id'] ?>">
                    <button type="submit">Delete</button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
</section>
</main>
<script src="js/alerts.js" defer></script>

</body>
</html>
