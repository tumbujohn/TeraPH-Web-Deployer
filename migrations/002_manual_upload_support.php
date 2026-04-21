<?php
// =============================================================================
// Migration 002 — Manual Upload Support
// Adds source_type to the projects table, allowing projects to be deployed
// via a manual zip upload instead of fetching from GitHub.
// =============================================================================

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/Database.php';

$pdo = Database::connect();

try {
    $pdo->exec("ALTER TABLE projects ADD COLUMN source_type VARCHAR(20) NOT NULL DEFAULT 'github'");
    echo "Added 'source_type' column to projects table.\n";
} catch (PDOException $e) {
    // If the column already exists, this will throw an exception (e.g. SQLite duplicate column).
    // We catch it and ignore it so the migration is idempotent.
    if (strpos($e->getMessage(), 'duplicate column name') !== false || strpos($e->getMessage(), 'Duplicate column name') !== false) {
        // Safe to ignore
    } else {
        throw $e;
    }
}

// Modify existing repo_url column to allow NULL, since manual projects don't have one.
// Note: SQLite does not support ALTER TABLE ... MODIFY COLUMN or ALTER COLUMN.
// MySQL does. To remain cross-compatible without a table rebuild, we will simply 
// pass empty strings '' instead of NULL for repo_url in the application logic. 
// The existing table schema has repo_url TEXT NOT NULL, this is fine.
