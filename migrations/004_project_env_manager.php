<?php
// =============================================================================
// Migration 004 — Per-Project .env Manager
//
// Adds env_mode and env_template columns to the projects table and creates the
// project_env_vars table for encrypted per-key storage.
//
// Non-destructive: idempotent — safe to run multiple times.
// =============================================================================

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/Database.php';

$pdo = Database::connect();

// ---- projects: new columns --------------------------------------------------

$alterations = [
    'env_mode'     => "ALTER TABLE projects ADD COLUMN env_mode     VARCHAR(10) NOT NULL DEFAULT 'none'",
    'env_template' => "ALTER TABLE projects ADD COLUMN env_template TEXT",
];

foreach ($alterations as $col => $sql) {
    try {
        $pdo->exec($sql);
        echo "Added column '{$col}' to projects table.\n";
    } catch (PDOException $e) {
        if (stripos($e->getMessage(), 'duplicate column') !== false) {
            // Already exists — idempotent
        } else {
            throw $e;
        }
    }
}

// ---- project_env_vars -------------------------------------------------------

$pdo->exec("
    CREATE TABLE IF NOT EXISTS project_env_vars (
        id          INTEGER      NOT NULL,
        project_id  INTEGER      NOT NULL,
        key_name    VARCHAR(255) NOT NULL,
        value_enc   TEXT,
        is_required INTEGER      NOT NULL DEFAULT 0,
        sort_order  INTEGER      NOT NULL DEFAULT 0,
        updated_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        FOREIGN KEY (project_id) REFERENCES projects (id)
    )
");

try {
    $pdo->exec("CREATE UNIQUE INDEX idx_env_vars_project_key ON project_env_vars (project_id, key_name)");
} catch (PDOException $e) {
    // Index already exists
}

echo "project_env_vars table ready.\n";
