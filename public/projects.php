<?php
// =============================================================================
// Projects Endpoint (AJAX) — CRUD for project management
// =============================================================================
require_once __DIR__ . '/../app/helpers.php';
app_boot();

Auth::require();

$action = $_GET['action'] ?? 'list';

// ---- List all projects -------------------------------------------------------
if ($action === 'list') {
    json_ok(['projects' => Project::all()]);
}

// ---- Get single project for editing -----------------------------------------
if ($action === 'get') {
    $id      = (int) ($_GET['id'] ?? 0);
    $project = Project::find($id);
    if (!$project) {
        json_error('Project not found.', 404);
    }

    // Mask the PAT — send only whether it is set, not the raw value.
    // The JS uses this to show a placeholder ("PAT saved…" vs "none saved").
    $project['github_pat'] = !empty($project['github_pat']) ? '__set__' : '';

    json_ok(['project' => $project]);
}

// ---- Create -----------------------------------------------------------------
if ($action === 'create') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_error('Method not allowed.', 405);
    }

    csrf_verify();

    $data   = sanitizeProjectInput($_POST);
    $errors = validateProjectInput($data);

    if (!empty($errors)) {
        json_error(implode(' ', $errors), 422);
    }

    if (Project::nameExists($data['name'])) {
        json_error("A project named '{$data['name']}' already exists.", 409);
    }

    $id = Project::create($data);
    Project::setPhpBinary($id, $data['php_binary']);
    Project::setPhpExtensions($id, $data['php_extensions']);
    
    // Handle manual archive upload if provided
    $uploadError = handleManualArchiveUpload($id);
    if ($uploadError) {
        // Rollback creation
        Project::delete($id);
        json_error($uploadError, 422);
    }

    json_ok(['id' => $id], "Project '{$data['name']}' created.");
}

// ---- Update -----------------------------------------------------------------
if ($action === 'update') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_error('Method not allowed.', 405);
    }

    csrf_verify();

    $id      = (int) ($_POST['id'] ?? 0);
    $project = Project::find($id);
    if (!$project) {
        json_error('Project not found.', 404);
    }

    $data   = sanitizeProjectInput($_POST);
    $errors = validateProjectInput($data);

    if (!empty($errors)) {
        json_error(implode(' ', $errors), 422);
    }

    if (Project::nameExists($data['name'], $id)) {
        json_error("A project named '{$data['name']}' already exists.", 409);
    }

    Project::update($id, $data);
    Project::setPhpBinary($id, $data['php_binary']);
    Project::setPhpExtensions($id, $data['php_extensions']);

    // Handle manual archive upload if provided (overwrites existing if any)
    $uploadError = handleManualArchiveUpload($id);
    if ($uploadError) {
        json_error("Project updated, but archive upload failed: " . $uploadError, 422);
    }

    json_ok(null, "Project '{$data['name']}' updated.");
}

// ---- Delete -----------------------------------------------------------------
if ($action === 'delete') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_error('Method not allowed.', 405);
    }

    csrf_verify();

    $id      = (int) ($_POST['id'] ?? 0);
    $project = Project::find($id);
    if (!$project) {
        json_error('Project not found.', 404);
    }

    Project::delete($id);
    json_ok(null, "Project '{$project['name']}' deleted.");
}

json_error('Unknown action.', 400);

// =============================================================================
// Input helpers (local to this file only)
// =============================================================================

function sanitizeProjectInput(array $post): array
{
    $validTemplates = ['none', 'laravel', 'codeigniter', 'wordpress', 'symfony'];
    $template       = trim($post['deploy_template'] ?? 'none');

    return [
        'name'              => trim($post['name']        ?? ''),
        'source_type'       => trim($post['source_type'] ?? 'github'),
        'repo_url'          => trim($post['repo_url']    ?? ''),
        'target_path'       => trim($post['target_path'] ?? ''),
        'branch'            => trim($post['branch']      ?? 'main'),
        'safe_keep'         => !empty($post['safe_keep'])
                                   ? json_encode(array_map('trim', explode(',', $post['safe_keep'])))
                                   : null,
        'github_pat'        => trim($post['github_pat']  ?? '') ?: null,
        'keep_pat'          => !empty($post['keep_pat']),
        'deploy_template'   => in_array($template, $validTemplates, true) ? $template : 'none',
        'pre_deploy_hooks'  => trim($post['pre_deploy_hooks']  ?? '') ?: null,
        'post_deploy_hooks' => trim($post['post_deploy_hooks'] ?? '') ?: null,
        'terminal_enabled'  => isset($post['terminal_enabled']) ? (int)(bool)$post['terminal_enabled'] : 1,
        'env_mode'          => trim($post['env_mode'] ?? '') === 'managed' ? 'managed' : 'none',
        'php_binary'        => trim($post['php_binary'] ?? '') ?: null,   // saved by Project::setPhpBinary()
        'php_extensions_raw' => trim($post['php_extensions'] ?? ''),        // validated below; saved normalised
        'php_extensions'    => implode(', ', HookRunner::parseExtensions($post['php_extensions'] ?? '')) ?: null,
    ];
}

function validateProjectInput(array $data): array
{
    $errors = [];

    if (empty($data['name'])) {
        $errors[] = 'Project name is required.';
    } elseif (!preg_match('/^[a-zA-Z0-9_\-]+$/', $data['name'])) {
        $errors[] = 'Project name may only contain letters, numbers, hyphens, and underscores.';
    }

    if ($data['source_type'] === 'github') {
        if (empty($data['repo_url'])) {
            $errors[] = 'Repository URL is required for GitHub projects.';
        } elseif (!filter_var($data['repo_url'], FILTER_VALIDATE_URL)) {
            $errors[] = 'Repository URL must be a valid HTTPS URL.';
        }
    }

    if (empty($data['target_path'])) {
        $errors[] = 'Target path is required.';
    }

    if (($data['php_extensions_raw'] ?? '') !== '') {
        foreach (preg_split('/[\s,;]+/', $data['php_extensions_raw'], -1, PREG_SPLIT_NO_EMPTY) ?: [] as $name) {
            if (!preg_match('/^(ext-)?[A-Za-z0-9_]+$/', $name)) {
                $errors[] = "PHP extension names may only contain letters, digits and underscores (got '{$name}').";
                break;
            }
        }
    }

    if (!empty($data['php_binary']) && !HookRunner::usableBinary($data['php_binary'])) {
        $errors[] = 'PHP binary must be the absolute path of an executable PHP CLI on this server'
                  . ' (nothing was found at: ' . $data['php_binary'] . '). Leave it empty for the automatic choice.';
    }

    return $errors;
}

/**
 * Handles the manual archive upload. 
 * Returns an error string on failure, or null on success/skip.
 */
function handleManualArchiveUpload(int $projectId): ?string
{
    // Check if an archive was uploaded at all
    if (!isset($_FILES['archive']) || $_FILES['archive']['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ($_FILES['archive']['error'] !== UPLOAD_ERR_OK) {
        return 'Upload failed with error code: ' . $_FILES['archive']['error'];
    }

    $ext = strtolower(pathinfo($_FILES['archive']['name'], PATHINFO_EXTENSION));
    if ($ext !== 'zip') {
        return 'Uploaded file must be a .zip archive.';
    }

    // Ensure archives directory exists
    $archiveDir = STORAGE_PATH . '/archives';
    if (!is_dir($archiveDir) && !mkdir($archiveDir, 0755, true)) {
        return 'Server error: Cannot create archive storage directory.';
    }

    $destPath = $archiveDir . '/project_' . $projectId . '.zip';
    if (!move_uploaded_file($_FILES['archive']['tmp_name'], $destPath)) {
        return 'Server error: Failed to save uploaded archive.';
    }

    return null;
}
