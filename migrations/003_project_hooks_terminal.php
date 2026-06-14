<?php
// =============================================================================
// Migration 003 — Project Deploy Hooks & Terminal Controls
//
// Adds per-project deployment template, pre/post hook commands, and a
// per-project terminal enable toggle to the projects table.
// Also creates the terminal_logs audit table.
//
// Non-destructive: all ALTER TABLE operations are wrapped in try/catch so
// re-running this migration is safe (idempotent).
// =============================================================================

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/Database.php';

$pdo = Database::connect();

// ---- projects: new columns --------------------------------------------------

$alterations = [
    "deploy_template"   => "ALTER TABLE projects ADD COLUMN deploy_template   VARCHAR(30) NOT NULL DEFAULT 'none'",
    "pre_deploy_hooks"  => "ALTER TABLE projects ADD COLUMN pre_deploy_hooks  TEXT",
    "post_deploy_hooks" => "ALTER TABLE projects ADD COLUMN post_deploy_hooks TEXT",
    "terminal_enabled"  => "ALTER TABLE projects ADD COLUMN terminal_enabled  INTEGER     NOT NULL DEFAULT 1",
];

foreach ($alterations as $col => $sql) {
    try {
        $pdo->exec($sql);
        echo "Added column '{$col}' to projects table.\n";
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (stripos($msg, 'duplicate column') !== false) {
            // Already exists — safe to ignore on re-run
        } else {
            throw $e;
        }
    }
}

// ---- terminal_logs ----------------------------------------------------------

$pdo->exec("
    CREATE TABLE IF NOT EXISTS terminal_logs (
        id          INTEGER      NOT NULL,
        project_id  INTEGER      NOT NULL,
        user        VARCHAR(100) NOT NULL,
        ip          VARCHAR(45)  NOT NULL,
        command     TEXT         NOT NULL,
        exit_code   INTEGER,
        executed_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    )
");

echo "terminal_logs table ready.\n";
