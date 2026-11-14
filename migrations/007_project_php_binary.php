<?php
// =============================================================================
// Migration 007 — Per-project PHP binary
//
// Adds projects.php_binary: an optional absolute path to the PHP CLI that this
// project's deploy hooks, Apply now command and web terminal run with. Empty
// means "automatic" (see HookRunner::resolvePhp()).
//
// Non-destructive: idempotent — safe to run multiple times.
// =============================================================================

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/Database.php';

$pdo = Database::connect();

try {
    $pdo->exec("ALTER TABLE projects ADD COLUMN php_binary TEXT");
    echo "Added column 'php_binary' to projects table.\n";
} catch (PDOException $e) {
    if (stripos($e->getMessage(), 'duplicate column') === false) {
        throw $e;
    }
    // Already exists — idempotent
}
