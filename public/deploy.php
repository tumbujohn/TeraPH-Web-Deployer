<?php
// =============================================================================
// Deploy Action Endpoint (AJAX)
// =============================================================================
// Supports two modes:
//   POST (no ?action)           — run the deployment pipeline
//   GET  ?action=status         — return the real DB status of latest deployment
// Global output buffer ensures stray PHP warnings never corrupt JSON.
ob_start();

require_once __DIR__ . '/../app/helpers.php';
app_boot();
ob_clean(); // Discard any accidental output from bootstrap

Auth::require();

// ---- GET: Return real deployment status for a project ----------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'status') {
    $projectId = (int) ($_GET['project_id'] ?? 0);
    if ($projectId <= 0) {
        ob_end_clean();
        json_error('Invalid project_id.');
    }

    // isLocked() auto-releases stale locks as a side-effect
    Deployment::isLocked($projectId);

    $dep = Deployment::latestForProject($projectId);
    if (!$dep) {
        ob_end_clean();
        json_error('No deployments found for this project.', 404);
    }

    // Find the last error message in the logs for a useful failure hint
    $logs      = DeploymentLog::forDeployment((int) $dep['id']);
    $lastError = null;
    foreach (array_reverse($logs) as $entry) {
        if ($entry['level'] === 'ERROR') {
            $lastError = $entry['message'];
            break;
        }
    }

    ob_end_clean();
    json_ok([
        'deployment_id' => (int) $dep['id'],
        'status'        => $dep['status'],
        'mode'          => $dep['mode'],
        'started_at'    => $dep['started_at'],
        'finished_at'   => $dep['finished_at'],
        'last_error'    => $lastError,
    ]);
}

// ---- POST: Run the deployment pipeline -------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_end_clean();
    json_error('Method not allowed.', 405);
}

csrf_verify();

$projectId = (int) ($_POST['project_id'] ?? 0);
$mode      = in_array($_POST['mode'] ?? '', ['safe', 'full']) ? $_POST['mode'] : 'safe';

if ($projectId <= 0) {
    ob_end_clean();
    json_error('Invalid project ID.');
}

// Ensure the pipeline runs to completion even if the browser disconnects.
// Without this, PHP aborts when the client closes the connection mid-download,
// leaving the deployment stuck in 'running' state.
ignore_user_abort(true);
set_time_limit(0);

try {
    $deployer = new DeployService();
    $result   = $deployer->deploy($projectId, $mode, Auth::user());
} catch (Throwable $e) {
    ob_end_clean();
    json_error('Unexpected server error: ' . $e->getMessage());
}

ob_end_clean();

if ($result['success']) {
    json_ok(['deployment_id' => $result['deployment_id']], $result['message']);
} else {
    json_error($result['message']);
}
