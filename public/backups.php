<?php
// =============================================================================
// Backups Endpoint (AJAX) — list and restore backups
// =============================================================================
require_once __DIR__ . '/../app/helpers.php';
app_boot();

Auth::require();

$action    = $_GET['action'] ?? 'list';
$projectId = (int) ($_GET['project_id'] ?? 0);

if ($projectId <= 0) {
    json_error('Invalid project ID.');
}

$project = Project::find($projectId);
if (!$project) {
    json_error('Project not found.', 404);
}

$backupService = new BackupService();

// ---- List backups -----------------------------------------------------------
if ($action === 'list') {
    $backups = $backupService->listForProject($project['name']);
    $formatted = array_map(fn($b) => [
        'name'        => $b['name'],
        'path'        => $b['path'],
        'size'        => format_bytes($b['size']),
        'created_at'  => $b['created_at'],
        'time_ago'    => time_ago($b['created_at']),
    ], $backups);

    json_ok(['backups' => $formatted, 'project' => $project['name']]);
}

// ---- Restore a backup -------------------------------------------------------
if ($action === 'restore') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_error('Method not allowed.', 405);
    }

    csrf_verify();

    $backupName = basename($_POST['backup_name'] ?? '');

    if (empty($backupName)) {
        json_error('No backup name specified.');
    }

    // Security: resolve to BACKUP_PATH only
    $backupPath = realpath(BACKUP_PATH . '/' . $backupName);
    if (!$backupPath || !str_starts_with($backupPath, realpath(BACKUP_PATH))) {
        json_error('Invalid backup path.', 403);
    }

    if (!file_exists($backupPath)) {
        json_error('Backup file not found.', 404);
    }

    // Create a restore deployment record for the audit trail
    $deploymentId = Deployment::create([
        'project_id'   => $projectId,
        'status'       => Deployment::STATUS_RUNNING,
        'mode'         => 'restore',
        'triggered_by' => Auth::user(),
    ]);

    try {
        DeploymentLog::info($deploymentId, "Restore started from backup: {$backupName}");
        $backupService->restore($project, $backupPath, $deploymentId);
        DeploymentLog::info($deploymentId, "Restore completed successfully.");
        Deployment::updateStatus($deploymentId, Deployment::STATUS_SUCCESS, $backupPath);

        json_ok(['deployment_id' => $deploymentId], "Restore completed successfully.");

    } catch (Throwable $e) {
        DeploymentLog::error($deploymentId, "Restore failed: " . $e->getMessage());
        Deployment::updateStatus($deploymentId, Deployment::STATUS_FAILED, $backupPath);
        json_error("Restore failed: " . $e->getMessage());
    }
}

json_error('Unknown action.', 400);
