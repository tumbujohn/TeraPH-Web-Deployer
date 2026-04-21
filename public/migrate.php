<?php
// =============================================================================
// Browser-based Database Migration Runner
// Allows executing pending schema updates when SSH/CLI is unavailable.
// =============================================================================
require_once __DIR__ . '/../app/helpers.php';

// Bootstrap connections (does not auto-migrate all files, only schema 001 if nothing exists)
app_boot();

// Security: Enforce explicit login. 
// If deploying for the first time, use your config.php password.
Auth::require();

$migrationsDir = dirname(__DIR__) . '/migrations';
$files         = glob($migrationsDir . '/*.php');
$outputLog     = [];

if (empty($files)) {
    $outputLog[] = "No migration files found in {$migrationsDir}.";
} else {
    sort($files);
    
    // Prevent the default app_boot migration from echoing if it was already run.
    foreach ($files as $file) {
        $name = basename($file);
        
        ob_start();
        $start = microtime(true);
        
        // Execute the migration dynamically
        require $file;
        
        $duration = round((microtime(true) - $start) * 1000, 2);
        $output = trim(ob_get_clean());
        
        if ($output) {
            $outputLog[] = "✓ [{$name}] applied in {$duration}ms. Output: {$output}";
        } else {
            $outputLog[] = "✓ [{$name}] applied in {$duration}ms.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Database Migrations | TeraPH Deployer</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/app.css">
    <style>
        body { padding: 40px; background: #000; color: #e5e7eb; }
        .box { background: #111; padding: 30px; border-radius: 8px; border: 1px solid #333; max-width: 800px; margin: 0 auto; }
        h1 { margin-top: 0; color: #fff; font-size: 24px; border-bottom: 1px solid #333; padding-bottom: 15px; }
        pre { background: #000; padding: 15px; border-radius: 4px; border: 1px solid #222; white-space: pre-wrap; font-family: Consolas, monospace; color: #3ecf8e; }
        .back-btn { margin-top: 20px; display: inline-block; color: #3ecf8e; text-decoration: none; border: 1px solid #3ecf8e; padding: 8px 16px; border-radius: 4px; }
        .back-btn:hover { background: rgba(62, 207, 142, 0.1); }
    </style>
</head>
<body>
    <div class="box">
        <h1>Database Migration Runner</h1>
        <p>The following migration scripts have been scanned and securely executed against the configured database target:</p>
        
        <pre><?= implode("\n", array_map('h', $outputLog)) ?></pre>
        
        <div style="margin-top: 20px; color: #aaa; font-size: 13px;">
            Note: Migrations in TeraPH Deployer are designed to be idempotent (safe to run multiple times). 
        </div>
        
        <a href="index.php" class="back-btn">← Return to Dashboard</a>
    </div>
</body>
</html>

