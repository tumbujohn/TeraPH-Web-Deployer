<?php
// =============================================================================
// Migration 005 — Env "Apply now" command
//
// Adds projects.env_apply_cmd: the optional command(s) the .env Manager's
// "Apply now" action runs after writing the .env (e.g. `php artisan config:cache`).
//
// Non-destructive: idempotent — safe to run multiple times.
// =============================================================================

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/Database.php';

$pdo = Database::connect();

try {
    $pdo->exec("ALTER TABLE projects ADD COLUMN env_apply_cmd TEXT");
    echo "Added column 'env_apply_cmd' to projects table.\n";
} catch (PDOException $e) {
    if (stripos($e->getMessage(), 'duplicate column') === false) {
        throw $e;
    }
    // Already exists — idempotent
}
