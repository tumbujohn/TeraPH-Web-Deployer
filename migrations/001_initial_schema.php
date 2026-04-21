<?php
// =============================================================================
// Migration 001 — Initial Schema
// Non-destructive: only creates tables if they do not already exist.
// No SQLite-specific constructs — compatible with MySQL/MariaDB.
// =============================================================================

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/Database.php';

$pdo = Database::connect();

// ---- projects ---------------------------------------------------------------
$pdo->exec("
    CREATE TABLE IF NOT EXISTS projects (
        id          INTEGER      NOT NULL,
        name        VARCHAR(100) NOT NULL,
        repo_url    TEXT         NOT NULL,
        target_path TEXT         NOT NULL,
        branch      VARCHAR(50)  NOT NULL DEFAULT 'main',
        safe_keep   TEXT,
        github_pat  TEXT,
        created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at  TIMESTAMP,
        PRIMARY KEY (id)
    )
");

// SQLite does not enforce UNIQUE constraints via CREATE TABLE IF NOT EXISTS the
// same way MySQL does, so attempt to create the index separately (idempotent).
try {
    $pdo->exec("CREATE UNIQUE INDEX idx_projects_name ON projects (name)");
} catch (PDOException $e) {
    // Index already exists — safe to ignore
}

// ---- deployments ------------------------------------------------------------
$pdo->exec("
    CREATE TABLE IF NOT EXISTS deployments (
        id           INTEGER      NOT NULL,
        project_id   INTEGER      NOT NULL,
        status       VARCHAR(20)  NOT NULL DEFAULT 'pending',
        mode         VARCHAR(10)  NOT NULL DEFAULT 'safe',
        triggered_by VARCHAR(100) NOT NULL,
        started_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        finished_at  TIMESTAMP,
        backup_path  TEXT,
        PRIMARY KEY (id),
        FOREIGN KEY (project_id) REFERENCES projects (id)
    )
");

// ---- deployment_logs --------------------------------------------------------
$pdo->exec("
    CREATE TABLE IF NOT EXISTS deployment_logs (
        id            INTEGER     NOT NULL,
        deployment_id INTEGER     NOT NULL,
        level         VARCHAR(10) NOT NULL,
        message       TEXT        NOT NULL,
        logged_at     TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        FOREIGN KEY (deployment_id) REFERENCES deployments (id)
    )
");

// Migration complete — no output here (web-safe).
// The `php tera migrate` command captures and prints migration status via ob_start().
