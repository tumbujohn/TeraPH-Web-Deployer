<?php
// =============================================================================
// Settings Actions — AJAX endpoint for browser-triggered admin operations
// =============================================================================
require_once __DIR__ . '/../app/helpers.php';
app_boot();
Auth::require();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed.', 405);
}

csrf_verify();

$action = $_POST['action'] ?? '';

match ($action) {
    'clear_tmp'        => action_clear_tmp(),
    'deploy_reconcile' => action_deploy_reconcile(),
    default            => json_error('Unknown action.'),
};

// ---------------------------------------------------------------------------

function action_clear_tmp(): void
{
    $path  = defined('TMP_PATH') ? TMP_PATH : dirname(__DIR__) . '/storage/tmp';
    $count = clear_dir_contents($path);
    json_ok(['cleared' => $count], "Cleared {$count} file(s) from tmp.");
}

function action_deploy_reconcile(): void
{
    $pdo  = Database::connect();
    $stmt = $pdo->query("
        SELECT d.id, d.started_at, p.name AS project_name
        FROM deployments d
        JOIN projects p ON p.id = d.project_id
        WHERE d.status = 'running'
        ORDER BY d.started_at ASC
    ");
    $stuck = $stmt->fetchAll();

    $fixed   = 0;
    $details = [];

    foreach ($stuck as $dep) {
        $depId = (int) $dep['id'];
        $now   = date('Y-m-d H:i:s');

        $pdo->prepare("UPDATE deployments SET status = 'failed', finished_at = ? WHERE id = ?")
            ->execute([$now, $depId]);

        $pdo->prepare("
            INSERT INTO deployment_logs (deployment_id, level, message, logged_at)
            VALUES (?, 'WARNING', 'Marked failed by reconcile (Settings page).', ?)
        ")->execute([$depId, $now]);

        $details[] = "#{$depId} {$dep['project_name']}";
        $fixed++;
    }

    $msg = $fixed > 0
        ? "Fixed {$fixed} stuck deployment(s)."
        : 'No stuck deployments found. All clear.';

    json_ok(['fixed' => $fixed, 'details' => $details], $msg);
}

// ---------------------------------------------------------------------------

function clear_dir_contents(string $path): int
{
    if (!is_dir($path)) return 0;
    $skip     = ['.gitignore', '.htaccess', '.gitkeep'];
    $count    = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isFile() && in_array($item->getFilename(), $skip, true)) continue;
        if ($item->isDir()) { @rmdir($item->getRealPath()); }
        else                { @unlink($item->getRealPath()); $count++; }
    }
    return $count;
}
