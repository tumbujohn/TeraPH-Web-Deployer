<?php
// =============================================================================
// TeraPH Web Deployer — Database Reset Script
//
// Wipes all application data so a server install can be tested cleanly.
// Safe to re-run — all operations are idempotent.
//
// Usage (CLI only):
//   php scripts/reset_db.php
//   php scripts/reset_db.php --force     # skip confirmation prompt
//
// This script is intentionally CLI-only. It cannot be accessed via a browser
// because scripts/ is not routed through the public webroot.
// =============================================================================

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/app/Database.php';

// ---- Confirmation -----------------------------------------------------------

$force = in_array('--force', $argv ?? [], true);

if (!$force) {
    echo "TeraPH Web Deployer — Database Reset\n";
    echo str_repeat('=', 50) . "\n";
    echo "This will permanently delete ALL data:\n";
    echo "  • All projects\n";
    echo "  • All deployments and logs\n";
    echo "  • All terminal logs\n";
    echo "  • All managed .env variables\n";
    echo "\nDatabase: " . (defined('DB_PATH') ? DB_PATH : 'MySQL (' . DB_NAME . ')') . "\n";
    echo "\nType 'yes' to continue, anything else to cancel: ";
    $answer = trim((string) fgets(STDIN));
    if ($answer !== 'yes') {
        echo "Cancelled.\n";
        exit(0);
    }
}

// ---- Reset ------------------------------------------------------------------

$pdo = Database::connect();

echo "\nResetting database…\n";

$tables = [
    'project_env_vars',
    'terminal_logs',
    'deployment_logs',
    'deployments',
    'projects',
];

$deleted = [];
foreach ($tables as $table) {
    try {
        $stmt  = $pdo->exec("DELETE FROM {$table}");
        $count = $pdo->query("SELECT changes()")->fetchColumn();
        $deleted[$table] = (int) $count;
        echo "  Cleared {$table}: {$deleted[$table]} row(s)\n";
    } catch (PDOException $e) {
        // Table may not exist yet (migration not run) — skip silently
        echo "  Skipped {$table}: " . $e->getMessage() . "\n";
    }
}

// Reset SQLite auto-increment sequences
if (defined('DB_DRIVER') && DB_DRIVER === 'sqlite') {
    try {
        $pdo->exec("DELETE FROM sqlite_sequence");
        echo "  Reset sqlite_sequence (auto-increment counters)\n";
    } catch (PDOException) {
        // sqlite_sequence doesn't exist if no rows have ever been inserted — fine
    }
}

// ---- Clear storage runtime files -------------------------------------------

echo "\nClearing storage runtime files…\n";

$clearDirs = [
    'logs'     => defined('LOG_PATH')    ? LOG_PATH    : $root . '/storage/logs',
    'backups'  => defined('BACKUP_PATH') ? BACKUP_PATH : $root . '/storage/backups',
    'tmp'      => defined('TMP_PATH')    ? TMP_PATH    : $root . '/storage/tmp',
    'archives' => $root . '/storage/archives',
];

$skip = ['.gitignore', '.htaccess', '.gitkeep'];

foreach ($clearDirs as $label => $path) {
    if (!is_dir($path)) {
        echo "  Skipped {$label}: directory not found\n";
        continue;
    }
    $count    = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if (in_array($item->getFilename(), $skip, true)) continue;
        if ($item->isDir()) {
            @rmdir($item->getRealPath());
        } else {
            @unlink($item->getRealPath());
            $count++;
        }
    }
    echo "  Cleared {$label}: {$count} file(s)\n";
}

// Clear cached deploy strategy so it re-detects on next deploy
$strategyCache = $root . '/storage/deploy_strategy.txt';
if (file_exists($strategyCache)) {
    @unlink($strategyCache);
    echo "  Removed deploy_strategy.txt cache\n";
}

echo "\nDone. Run migrate.php (or visit /migrate.php) to re-initialise the schema.\n";
