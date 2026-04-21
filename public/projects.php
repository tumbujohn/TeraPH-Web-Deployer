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
    return [
        'name'        => trim($post['name']        ?? ''),
        'repo_url'    => trim($post['repo_url']    ?? ''),
        'target_path' => trim($post['target_path'] ?? ''),
        'branch'      => trim($post['branch']      ?? 'main'),
        'safe_keep'   => !empty($post['safe_keep'])
                            ? json_encode(array_map('trim', explode(',', $post['safe_keep'])))
                            : null,
        'github_pat'  => trim($post['github_pat']  ?? '') ?: null,
        // keep_pat=1 means: if github_pat is blank, preserve the existing DB value
        'keep_pat'    => !empty($post['keep_pat']),
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

    if (empty($data['repo_url'])) {
        $errors[] = 'Repository URL is required.';
    } elseif (!filter_var($data['repo_url'], FILTER_VALIDATE_URL)) {
        $errors[] = 'Repository URL must be a valid HTTPS URL.';
    }

    if (empty($data['target_path'])) {
        $errors[] = 'Target path is required.';
    }

    return $errors;
}
