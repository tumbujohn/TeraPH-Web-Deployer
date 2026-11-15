<?php
// =============================================================================
// Migration 008 — Per-project required PHP extensions
//
// Adds projects.php_extensions: an optional comma-separated list of PHP
// extensions (e.g. "pdo_pgsql, pgsql") that this project's hooks need. Before a
// deploy touches anything, the PHP the hooks will run is checked for them; an
// extension that is installed but not enabled is enabled for hook runs only.
//
// Non-destructive: idempotent — safe to run multiple times.
// =============================================================================

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/Database.php';

$pdo = Database::connect();

try {
    $pdo->exec("ALTER TABLE projects ADD COLUMN php_extensions TEXT");
    echo "Added column 'php_extensions' to projects table.\n";
} catch (PDOException $e) {
    if (stripos($e->getMessage(), 'duplicate column') === false) {
        throw $e;
    }
    // Already exists — idempotent
}
