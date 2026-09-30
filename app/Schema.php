<?php
declare(strict_types=1);

function migrateDatabase(PDO $db): void
{
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS gaugeiq_schema (
    version INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS gaugeiq_pressure_readings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    pressure_hpa REAL NOT NULL,
    observed_at TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS gaugeiq_settings (
    key VARCHAR(191) PRIMARY KEY,
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

    $version = (int)($db->query('SELECT version FROM gaugeiq_schema LIMIT 1')->fetchColumn() ?: 0);

    if ($version < 1) {
        $db->exec("INSERT INTO gaugeiq_schema (version) VALUES (1)");
    }
}
