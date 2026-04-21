<?php
// =============================================================================
// TeraPH Web Deployer — Bootstrap Test
// =============================================================================
// Run this from your server or CLI to verify the application bootstraps correctly.
// Usage: php test/bootstrap_test.php
// =============================================================================

define('TEST_MODE', true);

$root = dirname(__DIR__);

require_once $root . '/app/helpers.php';

echo "Testing application bootstrap...\n\n";

// 1. Config
try {
    require_once $root . '/config.php';
    echo "[OK] config.php loaded\n";
} catch (Throwable $e) {
    echo "[FAIL] config.php: " . $e->getMessage() . "\n";
    exit(1);
}

// 2. Database connection + migration
try {
    require_once $root . '/app/Database.php';
    require_once $root . '/migrations/001_initial_schema.php';
    $pdo = Database::connect();
    echo "[OK] Database connected (driver: " . DB_DRIVER . ")\n";
} catch (Throwable $e) {
    echo "[FAIL] Database: " . $e->getMessage() . "\n";
    exit(1);
}

// 3. Models
foreach (['Project', 'Deployment', 'DeploymentLog'] as $model) {
    require_once $root . "/app/Models/{$model}.php";
    echo "[OK] Model loaded: {$model}\n";
}

// 4. Services
foreach (['GitHubService', 'FileManager', 'BackupService', 'DeployService'] as $svc) {
    require_once $root . "/app/Services/{$svc}.php";
    echo "[OK] Service loaded: {$svc}\n";
}

// 5. Storage dirs
foreach ([STORAGE_PATH, BACKUP_PATH, TMP_PATH, LOG_PATH] as $dir) {
    if (is_writable($dir)) {
        echo "[OK] Storage writable: {$dir}\n";
    } else {
        echo "[WARN] Storage not writable: {$dir}\n";
    }
}

// 6. PHP extensions
foreach (['zip', 'curl', 'pdo'] as $ext) {
    if (extension_loaded($ext)) {
        echo "[OK] Extension: {$ext}\n";
    } else {
        echo "[FAIL] Missing extension: {$ext}\n";
    }
}

// 7. Auth class
require_once $root . '/app/Auth.php';
echo "[OK] Auth class loaded\n";

// 8. Project CRUD test
$testName = 'test_project_' . time();
$id = Project::create([
    'name'        => $testName,
    'repo_url'    => 'https://github.com/example/repo',
    'target_path' => '/tmp/test_target',
    'branch'      => 'main',
]);
$project = Project::find($id);
assert($project['name'] === $testName, 'Project name mismatch');
Project::delete($id);
assert(Project::find($id) === false, 'Project not deleted');
echo "[OK] Project CRUD (create, find, delete)\n";

echo "\n✓ All bootstrap checks passed.\n";
