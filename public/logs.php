<?php
// =============================================================================
// Logs Endpoint (AJAX)
// Supports two modes:
//   ?deployment_id=X         — fetch logs for a specific deployment
//   ?project_id=X            — fetch logs for the latest deployment of a project
// =============================================================================
require_once __DIR__ . '/../app/helpers.php';
app_boot();

Auth::require();

$deploymentId = (int) ($_GET['deployment_id'] ?? 0);
$projectId    = (int) ($_GET['project_id']    ?? 0);

// ---- Resolve deployment ID from project if needed --------------------------
if ($deploymentId <= 0 && $projectId > 0) {
    $latest = Deployment::latestForProject($projectId);
    if (!$latest) {
        json_ok([
            'deployment' => null,
            'logs'       => [],
            'message'    => 'No deployments found for this project.',
        ], 'No deployments yet.');
    }
    $deploymentId = (int) $latest['id'];
}

if ($deploymentId <= 0) {
    json_error('Provide deployment_id or project_id.');
}

$deployment = Deployment::find($deploymentId);
if (!$deployment) {
    json_error('Deployment not found.', 404);
}

$sinceId = (int) ($_GET['since_id'] ?? 0);
$logs    = DeploymentLog::forDeployment($deploymentId, $sinceId);

json_ok([
    'deployment'   => $deployment,
    'logs'         => $logs,
    'last_log_id'  => !empty($logs) ? (int) end($logs)['id'] : $sinceId,
]);
