<?php
declare(strict_types=1);

function migrateDatabase(PDO $db): void
{
    $driver = (string)$db->getAttribute(PDO::ATTR_DRIVER_NAME);

    if ($driver === 'mysql') {
        $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS gaugeiq_schema (
    version INT NOT NULL
);

CREATE TABLE IF NOT EXISTS gaugeiq_pressure_readings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    pressure_hpa DOUBLE NOT NULL,
    humidity_percent DOUBLE NULL,
    wind_speed_kmh DOUBLE NULL,
    wind_direction_degrees DOUBLE NULL,
    observed_at VARCHAR(64) NOT NULL,
    created_at VARCHAR(64) NOT NULL,
    INDEX idx_gaugeiq_pressure_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS gaugeiq_settings (
    `key` VARCHAR(191) NOT NULL PRIMARY KEY,
    `value` TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS gaugeiq_push_subscriptions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    endpoint VARCHAR(2048) NOT NULL,
    subscription_json LONGTEXT NOT NULL,
    created_at VARCHAR(64) NOT NULL,
    updated_at VARCHAR(64) NOT NULL,
    UNIQUE KEY uq_gaugeiq_push_endpoint (endpoint(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL);
    } elseif ($driver === 'sqlite') {
        $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS gaugeiq_schema (
    version INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS gaugeiq_pressure_readings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    pressure_hpa REAL NOT NULL,
    humidity_percent REAL NULL,
    wind_speed_kmh REAL NULL,
    wind_direction_degrees REAL NULL,
    observed_at TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS gaugeiq_settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS gaugeiq_push_subscriptions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    endpoint TEXT NOT NULL UNIQUE,
    subscription_json TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
SQL);

        foreach ([
            'pressure_readings' => 'gaugeiq_pressure_readings',
            'settings' => 'gaugeiq_settings',
            'push_subscriptions' => 'gaugeiq_push_subscriptions',
        ] as $old => $new) {
            $oldExists = (bool)$db->query(
                "SELECT 1 FROM sqlite_master WHERE type='table' AND name=" . $db->quote($old)
            )->fetchColumn();

            $newCount = (int)$db->query("SELECT COUNT(*) FROM {$new}")->fetchColumn();

            if ($oldExists && $newCount === 0) {
                $db->exec("INSERT INTO {$new} SELECT * FROM {$old}");
            }
        }
    } else {
        throw new RuntimeException('Unsupported database driver: ' . $driver);
    }

    $version = (int)($db->query('SELECT version FROM gaugeiq_schema LIMIT 1')->fetchColumn() ?: 0);

    if ($version === 0) {
        $db->exec("INSERT INTO gaugeiq_schema (version) VALUES (2)");
        $version = 2;
    }

    if ($version === 2) {
        if ($driver === 'mysql') {
            $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS gaugeiq_alert_rules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(191) NOT NULL,
    metric VARCHAR(64) NOT NULL,
    condition_type VARCHAR(64) NOT NULL,
    configuration_json TEXT NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    cooldown_minutes INT NOT NULL DEFAULT 60,
    last_triggered_at VARCHAR(64) NULL,
    created_at VARCHAR(64) NOT NULL,
    updated_at VARCHAR(64) NOT NULL,
    INDEX idx_gaugeiq_alert_enabled (enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS gaugeiq_alert_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    rule_id BIGINT UNSIGNED NOT NULL,
    observed_at VARCHAR(64) NOT NULL,
    message TEXT NOT NULL,
    created_at VARCHAR(64) NOT NULL,
    INDEX idx_gaugeiq_alert_events_rule (rule_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL);
        } else {
            $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS gaugeiq_alert_rules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    metric TEXT NOT NULL,
    condition_type TEXT NOT NULL,
    configuration_json TEXT NOT NULL,
    enabled INTEGER NOT NULL DEFAULT 1,
    cooldown_minutes INTEGER NOT NULL DEFAULT 60,
    last_triggered_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS gaugeiq_alert_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    rule_id INTEGER NOT NULL,
    observed_at TEXT NOT NULL,
    message TEXT NOT NULL,
    created_at TEXT NOT NULL
);
SQL);
        }

        $db->exec("UPDATE gaugeiq_schema SET version = 3");
        $version = 3;
    }

    if ($version === 1) {
        if ($driver === 'mysql') {
            $db->exec("ALTER TABLE gaugeiq_pressure_readings
                ADD COLUMN humidity_percent DOUBLE NULL,
                ADD COLUMN wind_speed_kmh DOUBLE NULL,
                ADD COLUMN wind_direction_degrees DOUBLE NULL");
        } else {
            $db->exec("ALTER TABLE gaugeiq_pressure_readings ADD COLUMN humidity_percent REAL NULL");
            $db->exec("ALTER TABLE gaugeiq_pressure_readings ADD COLUMN wind_speed_kmh REAL NULL");
            $db->exec("ALTER TABLE gaugeiq_pressure_readings ADD COLUMN wind_direction_degrees REAL NULL");
        }

        $db->exec("UPDATE gaugeiq_schema SET version = 2");
    }
}
