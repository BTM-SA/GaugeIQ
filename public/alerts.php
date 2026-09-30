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
<style>
.alert-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.alert-grid .full{grid-column:1/-1}
.alert-form label{display:block;font-weight:700;margin:14px 0 6px}
.alert-form input,.alert-form select{width:100%;padding:12px;border:1px solid #d1d5db;border-radius:12px;font:inherit;background:var(--card,#fff);color:inherit}
.alert-card{display:flex;justify-content:space-between;gap:16px;align-items:center}
.alert-meta{color:#6b7280;font-size:14px}
.alert-actions{display:flex;gap:8px;flex-wrap:wrap}
.alert-actions button{padding:9px 12px}
.notice{padding:12px 14px;border-radius:12px;background:#ecfdf5;color:#065f46;margin-bottom:16px}
.error{padding:12px 14px;border-radius:12px;background:#fef2f2;color:#991b1b;margin-bottom:16px}
.back{display:inline-block;margin-bottom:18px;color:inherit;text-decoration:none;font-weight:700}
@media(max-width:560px){.alert-grid{grid-template-columns:1fr}.alert-card{align-items:flex-start;flex-direction:column}}
</style>
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

            <div id="degreesField" hidden>
                <label for="degrees">Direction (degrees)</label>
                <input id="degrees" name="degrees" type="number" min="0" max="359" step="1" value="0">
            </div>

            <div id="speedField" hidden>
                <label for="speed_min">Minimum wind speed (km/h)</label>
                <input id="speed_min" name="speed_min" type="number" min="0" step="0.1" value="40">
            </div>

            <div id="fromField" hidden>
                <label for="direction_from">Direction from (degrees)</label>
                <input id="direction_from" name="direction_from" type="number" min="0" max="359" step="1" value="315">
            </div>

            <div id="toField" hidden>
                <label for="direction_to">Direction to (degrees)</label>
                <input id="direction_to" name="direction_to" type="number" min="0" max="359" step="1" value="45">
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
                    <?= h((string)$rule['metric']) ?> · <?= h((string)$rule['condition_type']) ?>
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
<script>
const metric=document.getElementById('metric');
const condition=document.getElementById('condition_type');
const valueField=document.getElementById('valueField');
const degreesField=document.getElementById('degreesField');
const speedField=document.getElementById('speedField');
const fromField=document.getElementById('fromField');
const toField=document.getElementById('toField');

function refreshFields(){
    const m=metric.value;
    const c=condition.value;
    const direction=m==='wind_direction' && c==='specific';
    const combined=m==='wind' && c==='speed_and_direction';
    degreesField.hidden=!direction;
    valueField.hidden=direction||combined;
    speedField.hidden=!combined;
    fromField.hidden=!combined;
    toField.hidden=!combined;
    if(m==='wind_direction'){
        [...condition.options].forEach(o=>o.hidden=!['change','specific'].includes(o.value));
        if(!['change','specific'].includes(condition.value)) condition.value='change';
    } else if(m==='wind'){
        [...condition.options].forEach(o=>o.hidden=o.value!=='speed_and_direction');
        condition.value='speed_and_direction';
    } else {
        [...condition.options].forEach(o=>o.hidden=['specific','speed_and_direction'].includes(o.value));
        if(['specific','speed_and_direction'].includes(condition.value)) condition.value='change';
    }
}
metric.addEventListener('change',refreshFields);
condition.addEventListener('change',refreshFields);
refreshFields();
</script>
</body>
</html>
