<?php
// =============================================================================
// TeraPH Web Deployer — Installation Probe
// DELETE THIS FILE after diagnosing the issue.
// =============================================================================

// Show all errors on screen so we can see what's failing
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

header('Content-Type: text/plain; charset=utf-8');

$root = dirname(__DIR__);

$checks = [];

// ---- PHP version ----
$phpOk         = version_compare(PHP_VERSION, '8.1.0', '>=');
$checks['PHP'] = ($phpOk ? 'OK' : 'FAIL') . '  PHP ' . PHP_VERSION . ($phpOk ? '' : '  (need 8.1+)');

// ---- Required extensions ----
foreach (['pdo', 'pdo_sqlite', 'openssl', 'curl', 'zip', 'json', 'mbstring'] as $ext) {
    $loaded           = extension_loaded($ext);
    $checks[$ext]     = ($loaded ? 'OK' : 'MISSING') . '  ext-' . $ext;
}

// ---- MySQL PDO (optional) ----
$checks['pdo_mysql'] = (extension_loaded('pdo_mysql') ? 'OK' : 'optional-missing') . '  ext-pdo_mysql';

// ---- Directory writability ----
foreach ([
    'storage'          => $root . '/storage',
    'storage/logs'     => $root . '/storage/logs',
    'storage/backups'  => $root . '/storage/backups',
    'storage/tmp'      => $root . '/storage/tmp',
] as $label => $path) {
    $exists   = is_dir($path);
    $writable = $exists && is_writable($path);
    $checks[$label] = ($writable ? 'OK' : ($exists ? 'NOT-WRITABLE' : 'MISSING')) . '  ' . $path;
}

// ---- config.php ----
$cfgPath = $root . '/config.php';
if (!file_exists($cfgPath)) {
    $checks['config.php'] = 'MISSING  ' . $cfgPath;
} else {
    $checks['config.php'] = 'OK  ' . $cfgPath . '  (' . number_format(filesize($cfgPath)) . ' bytes)';
}

// ---- Load config and test DB ----
if (file_exists($cfgPath)) {
    try {
        require_once $cfgPath;
        $checks['config_load'] = 'OK  config.php loaded without errors';
    } catch (Throwable $e) {
        $checks['config_load'] = 'FAIL  config.php threw: ' . $e->getMessage();
    }

    if (defined('DB_DRIVER') && defined('DB_PATH') && DB_DRIVER === 'sqlite') {
        try {
            require_once $root . '/app/Database.php';
            $pdo = Database::connect();
            $pdo->query('SELECT 1');
            $checks['db_connect'] = 'OK  SQLite connected: ' . DB_PATH;
        } catch (Throwable $e) {
            $checks['db_connect'] = 'FAIL  DB: ' . $e->getMessage();
        }
    } elseif (defined('DB_DRIVER') && DB_DRIVER === 'mysql') {
        $checks['db_connect'] = 'INFO  MySQL driver selected — skipping connection test in probe';
    } else {
        $checks['db_connect'] = 'INFO  Could not determine DB_DRIVER from config';
    }
}

// ---- Try loading bootstrap (catches class / syntax errors) ----
try {
    require_once $root . '/app/helpers.php';
    $checks['helpers'] = 'OK  helpers.php loaded';
} catch (Throwable $e) {
    $checks['helpers'] = 'FAIL  helpers.php: ' . $e->getMessage();
}

// ---- Output ----
echo "TeraPH Web Deployer — Installation Probe\n";
echo str_repeat('=', 60) . "\n";
echo 'Server:    ' . ($_SERVER['SERVER_SOFTWARE'] ?? 'unknown') . "\n";
echo 'Time:      ' . date('Y-m-d H:i:s T') . "\n";
echo 'Probe dir: ' . __DIR__ . "\n";
echo 'Root dir:  ' . $root . "\n";
echo str_repeat('-', 60) . "\n";

$fail = false;
foreach ($checks as $key => $result) {
    $isFail = str_starts_with($result, 'FAIL') || str_starts_with($result, 'MISSING') || str_starts_with($result, 'NOT-');
    if ($isFail) $fail = true;
    printf("%-20s %s\n", $key, $result);
}

echo str_repeat('=', 60) . "\n";
echo ($fail ? 'RESULT: PROBLEMS FOUND — see FAIL/MISSING lines above.' : 'RESULT: All checks passed.') . "\n";
echo "\n*** DELETE this file from the server after reading the output. ***\n";
