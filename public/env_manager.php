<?php
// =============================================================================
// .env Manager — Per-project environment variable editor
// =============================================================================
require_once __DIR__ . '/../app/helpers.php';
app_boot();
Auth::require();

$projects   = Project::all();
$projectId  = (int) ($_GET['project_id'] ?? 0);
$project    = $projectId ? Project::find($projectId) : null;

// If only one project exists and none selected, auto-select it
if (!$project && count($projects) === 1) {
    $project   = Project::find((int) $projects[0]['id']);
    $projectId = (int) $project['id'];
}

$flash  = get_flash();
$errors = [];

// Set by the template flow when a save would drop existing keys and needs confirming
$pendingRemoval = null;
// Set when pulling from live would replace stored values and needs confirming
$pendingPull = null;

// =============================================================================
// POST handlers
// =============================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $project) {
    csrf_verify();
    $postAction = $_POST['form_action'] ?? '';

    // ---- Toggle managed mode ------------------------------------------------
    if ($postAction === 'toggle_mode') {
        $newMode = ($_POST['env_mode'] ?? 'none') === 'managed' ? 'managed' : 'none';
        $pdo     = Database::connect();
        $pdo->prepare("UPDATE projects SET env_mode = ?, updated_at = ? WHERE id = ?")
            ->execute([$newMode, date('Y-m-d H:i:s'), $projectId]);
        $project['env_mode'] = $newMode;
        flash('success', $newMode === 'managed'
            ? 'Managed .env enabled for this project.'
            : 'Managed .env disabled. The .env file will be preserved by safe_keep on future deploys.');
        header('Location: env_manager.php?project_id=' . $projectId);
        exit;
    }

    // ---- Save template / import .env.example --------------------------------
    // Both paths share one flow. Parses keys AND values from the text; non-empty
    // values are stored, so pasting a full .env captures everything at once.
    // Keys the new text drops are not deleted silently: if any would go, nothing
    // is written and the page asks whether to remove them or keep them.
    if ($postAction === 'save_template' || $postAction === 'import_example') {
        $isImport = $postAction === 'import_example';
        $template = null;

        if ($isImport) {
            $examplePath = rtrim($project['target_path'], '/\\') . DIRECTORY_SEPARATOR . '.env.example';
            if (!file_exists($examplePath)) {
                flash('error', '.env.example not found at: ' . $examplePath);
                header('Location: env_manager.php?project_id=' . $projectId);
                exit;
            }
            $template = (string) file_get_contents($examplePath);
        } else {
            $template = (string) ($_POST['env_template'] ?? '');
        }

        $decision = $isImport ? '' : (string) ($_POST['removal'] ?? '');
        $existing = EnvVar::allForProject($projectId);
        $removed  = array_values(array_diff(
            array_column($existing, 'key_name'),
            array_keys(env_parse_pairs($template))
        ));

        if (!empty($removed) && $decision === '') {
            $pendingRemoval = [
                'template'   => $template,
                'removed'    => $removed,
                'with_value' => array_values(array_column(
                    array_filter($existing, fn($v) => $v['value'] !== '' && in_array($v['key_name'], $removed, true)),
                    'key_name'
                )),
            ];
        } else {
            if (!empty($removed) && $decision === 'keep') {
                // Keep them live: re-add each as an empty stub so the template still
                // lists them and their stored values keep being written.
                $template = (rtrim($template) === '' ? '' : rtrim($template) . "\n")
                          . implode("=\n", $removed) . "=\n";
            }
            $gone = EnvVar::saveTemplate($projectId, $template);

            $count = count(env_parse_pairs($template));
            $msg   = ($isImport ? 'Imported .env.example — ' . $count . ' key(s) synced.'
                                : 'Template saved. ' . $count . ' key(s) synced.');
            if (!empty($gone)) {
                $msg .= ' Removed: ' . implode(', ', $gone) . '.';
            }
            flash('success', $msg);
            header('Location: env_manager.php?project_id=' . $projectId);
            exit;
        }
    }

    // Human summary of an EnvVar::merge() result
    $summarize = function (array $r): string {
        $parts = [];
        foreach (['added' => 'added', 'filled' => 'filled in', 'overwritten' => 'replaced', 'kept' => 'kept (stored value differs)'] as $k => $label) {
            if (!empty($r[$k])) {
                $parts[] = count($r[$k]) . ' ' . $label;
            }
        }
        return $parts ? implode(', ', $parts) . '.' : 'No changes — nothing new to import.';
    };

    // ---- Save values --------------------------------------------------------
    // Values are what you submit; an empty field intentionally clears the value.
    // Rows are saved in the order they were arranged (env_order[]), and the
    // template is reordered to match so the written .env follows it too.
    if ($postAction === 'save_values') {
        $submitted = (array) ($_POST['env_values'] ?? []);
        // Rows holding line breaks were shown with `\n` escapes (an <input> strips real ones)
        foreach ((array) ($_POST['env_escaped'] ?? []) as $escKey => $_) {
            if (isset($submitted[$escKey]) && is_string($submitted[$escKey])) {
                $submitted[$escKey] = env_unescape_lines($submitted[$escKey]);
            }
        }
        EnvVar::saveValues(
            $projectId,
            $submitted,
            (array) ($_POST['env_required'] ?? []),
            array_map('strval', (array) ($_POST['env_order'] ?? [])),
            $project['env_template'] ?? null
        );
        flash('success', 'Values saved.');
        header('Location: env_manager.php?project_id=' . $projectId);
        exit;
    }

    // ---- Scrub plaintext values out of the stored template ------------------
    // Older versions kept pasted text, values included, in projects.env_template.
    // (Migration 006 does this automatically; this is the manual fallback.) What is
    // deployed does not change: see EnvVar::scrubTemplate().
    if ($postAction === 'scrub_template') {
        $saved = EnvVar::scrubTemplate($projectId);
        flash('success', 'Template scrubbed — it no longer contains any values.'
            . ($saved ? ' Saved from the template (encrypted): ' . implode(', ', $saved) . '.' : ''));
        header('Location: env_manager.php?project_id=' . $projectId);
        exit;
    }

    // ---- Apply now / save apply command -------------------------------------
    if ($postAction === 'save_apply_cmd' || $postAction === 'apply_now') {
        if (isset($_POST['env_apply_cmd'])) {
            EnvVar::setApplyCommand($projectId, (string) $_POST['env_apply_cmd']);
        }
        if ($postAction === 'save_apply_cmd') {
            flash('success', 'Apply command saved.');
        } else {
            $res = (new EnvApplyService())->apply($projectId, isset($_POST['run_cmd']), (string) Auth::user());
            $note = $res['deployment_id'] ? ' (deployment #' . $res['deployment_id'] . ' in Logs)' : '';
            flash($res['success'] ? 'success' : 'error', ($res['success'] ? '' : 'Apply failed: ') . $res['message'] . $note);
        }
        header('Location: env_manager.php?project_id=' . $projectId);
        exit;
    }

    // ---- Pull from live ------------------------------------------------------
    // Imports the target's current .env (values included). Never deletes anything.
    // If the live file holds a different value for a key that already has a stored
    // one, nothing is imported until the page has asked what to do about those.
    if ($postAction === 'pull_live') {
        $livePath = EnvVar::targetEnvPath($project);
        $content  = is_file($livePath) && filesize($livePath) <= 1048576 ? @file_get_contents($livePath) : false;

        if ($content === false) {
            flash('error', is_file($livePath)
                ? 'Cannot read the live .env (unreadable, or larger than 1 MB): ' . $livePath
                : '.env not found at: ' . $livePath);
            header('Location: env_manager.php?project_id=' . $projectId);
            exit;
        }

        $pairs    = env_parse_pairs($content);
        $decision = (string) ($_POST['pull_decision'] ?? '');
        $stored   = array_column(EnvVar::allForProject($projectId), 'value', 'key_name');
        $differs  = [];
        foreach ($pairs as $k => $v) {
            $k = (string) $k;
            if ($v !== '' && isset($stored[$k]) && $stored[$k] !== '' && $stored[$k] !== $v) {
                $differs[] = $k;
            }
        }

        if (!empty($differs) && $decision === '') {
            $pendingPull = ['differs' => $differs];
        } else {
            $incoming = [];
            foreach ($pairs as $k => $v) {
                $incoming[] = ['key' => (string) $k, 'value' => $v];
            }
            $res = EnvVar::merge($projectId, $incoming, $project['env_template'] ?? null, $content, $decision === 'overwrite');
            flash('success', 'Pulled from live .env — ' . $summarize($res));
            header('Location: env_manager.php?project_id=' . $projectId);
            exit;
        }
    }

    // ---- Bulk edit (KEY=value text) ------------------------------------------
    if ($postAction === 'bulk_edit') {
        $res = EnvVar::bulkEdit($projectId, (string) ($_POST['bulk_text'] ?? ''), $project['env_template'] ?? null);
        $parts = [];
        if (!empty($res['updated'])) $parts[] = count($res['updated']) . ' updated';
        if (!empty($res['added']))   $parts[] = count($res['added'])   . ' added';
        flash('success', $parts ? 'Bulk edit applied — ' . implode(', ', $parts) . '.' : 'Bulk edit: no changes.');
        header('Location: env_manager.php?project_id=' . $projectId);
        exit;
    }

    // ---- Copy from another project -------------------------------------------
    if ($postAction === 'copy_from') {
        $srcId = (int) ($_POST['source_project'] ?? 0);
        $src   = ($srcId && $srcId !== $projectId) ? Project::find($srcId) : null;
        if (!$src) {
            flash('error', 'Choose a different project to copy from.');
        } else {
            $incoming = [];
            foreach (EnvVar::allForProject($srcId) as $v) {
                if (!$v['decrypt_failed']) {
                    $incoming[] = ['key' => $v['key_name'], 'value' => $v['value'], 'required' => (bool) $v['is_required']];
                }
            }
            $res = EnvVar::merge($projectId, $incoming, $project['env_template'] ?? null, $src['env_template'] ?? null, isset($_POST['overwrite']));
            flash('success', 'Copied from "' . $src['name'] . '" — ' . $summarize($res));
        }
        header('Location: env_manager.php?project_id=' . $projectId);
        exit;
    }

    // ---- Encrypted export (download) -----------------------------------------
    // The passphrase, not SECRET_KEY, protects the file, so it can move between installs.
    if ($postAction === 'export_bundle') {
        $pass = (string) ($_POST['passphrase'] ?? '');
        if (strlen($pass) < 10) {
            flash('error', 'Use a passphrase of at least 10 characters.');
        } else {
            try {
                $doc  = env_bundle_encrypt(EnvVar::exportPayload($project), $pass);
                $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $project['name']) . '.env-bundle.json';
                header('Content-Type: application/json');
                header('Content-Disposition: attachment; filename="' . $name . '"');
                header('Content-Length: ' . strlen($doc));
                header('Cache-Control: no-store');
                echo $doc;
                exit;
            } catch (RuntimeException $e) {
                flash('error', $e->getMessage());
            }
        }
        header('Location: env_manager.php?project_id=' . $projectId);
        exit;
    }

    // ---- Encrypted import ----------------------------------------------------
    if ($postAction === 'import_bundle') {
        $file = $_FILES['bundle'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ($file['size'] ?? 0) > 1048576
            || !is_uploaded_file($file['tmp_name'])) {
            flash('error', 'Choose a bundle file (max 1 MB).');
        } else {
            try {
                $payload  = env_bundle_decrypt((string) file_get_contents($file['tmp_name']), (string) ($_POST['passphrase'] ?? ''));
                $incoming = [];
                foreach ($payload['vars'] as $v) {
                    if (is_array($v) && isset($v['key'], $v['value']) && is_string($v['key']) && is_string($v['value'])) {
                        $incoming[] = ['key' => $v['key'], 'value' => $v['value'], 'required' => isset($v['required']) ? (bool) $v['required'] : null];
                    }
                }
                $tpl = isset($payload['template']) && is_string($payload['template']) ? $payload['template'] : null;
                $res = EnvVar::merge($projectId, $incoming, $project['env_template'] ?? null, $tpl, isset($_POST['overwrite']));
                flash('success', 'Imported bundle — ' . $summarize($res));
            } catch (RuntimeException $e) {
                flash('error', $e->getMessage());
            }
        }
        header('Location: env_manager.php?project_id=' . $projectId);
        exit;
    }
    // ---- Add single key -----------------------------------------------------
    if ($postAction === 'add_key') {
        $newKey = trim($_POST['new_key'] ?? '');
        if ($newKey === '' || preg_match('/[\s#=]/', $newKey)) {
            flash('error', 'Key name must be non-empty and cannot contain spaces, # or =.');
        } else {
            $existing = array_column(EnvVar::allForProject($projectId), 'key_name');
            if (in_array($newKey, $existing, true)) {
                flash('error', "Key '{$newKey}' already exists.");
            } else {
                // Also appends a stub to the template so structure stays in sync
                EnvVar::addKey($projectId, $newKey, count($existing), $project['env_template'] ?? null);
                flash('success', "Key '{$newKey}' added.");
            }
        }
        header('Location: env_manager.php?project_id=' . $projectId);
        exit;
    }

    // ---- Delete key ---------------------------------------------------------
    // Removes the stored value and the key's line in the template.
    if ($postAction === 'delete_key') {
        $delKey = $_POST['del_key'] ?? '';
        if ($delKey !== '') {
            EnvVar::deleteKey($projectId, $delKey, $project['env_template'] ?? null);
            flash('success', "Key '{$delKey}' removed.");
        }
        header('Location: env_manager.php?project_id=' . $projectId);
        exit;
    }

    // ---- Rename key ---------------------------------------------------------
    // Preserves the encrypted value, sort order and required flag, and renames
    // the key in the template text so structure stays in sync.
    if ($postAction === 'rename_key') {
        $oldKey = $_POST['old_key'] ?? '';
        $newKey = trim($_POST['new_key'] ?? '');

        if ($oldKey === '' || $newKey === '') {
            flash('error', 'Both old and new key names are required.');
        } elseif (preg_match('/[\s#=]/', $newKey)) {
            flash('error', 'New key name cannot contain spaces, # or =.');
        } else {
            $existingKeys = array_column(EnvVar::allForProject($projectId), 'key_name');
            if (!in_array($oldKey, $existingKeys, true)) {
                flash('error', "Key '{$oldKey}' not found.");
            } elseif (in_array($newKey, $existingKeys, true) && $newKey !== $oldKey) {
                flash('error', "Key '{$newKey}' already exists.");
            } else {
                EnvVar::renameKey($projectId, $oldKey, $newKey, $project['env_template'] ?? null);
                flash('success', "Key renamed '{$oldKey}' → '{$newKey}'.");
            }
        }
        header('Location: env_manager.php?project_id=' . $projectId);
        exit;
    }
}

// =============================================================================
// Page data
// =============================================================================

$envVars      = $project ? EnvVar::allForProject($projectId) : [];
$envMode      = $project['env_mode']    ?? 'none';
$template     = $project['env_template'] ?? '';
if ($pendingRemoval) {
    $template = $pendingRemoval['template']; // keep the unsaved edit in the textarea
}
$managed      = $envMode === 'managed';
$csrfField    = csrf_field();
$noSecretKey  = !defined('SECRET_KEY') || SECRET_KEY === '';
$unreadable   = array_column(array_filter($envVars, fn($v) => $v['decrypt_failed']), 'key_name');

// Live .env comparison (key names only), and whether the stored template still holds plaintext values
$live             = $project ? EnvVar::drift($project) : null;
$liveChip         = match ($live['state'] ?? 'no_file') {
    'in_sync'    => ['ok',   'In sync'],
    'drift'      => ['warn', 'Drift'],
    'unreadable' => ['bad',  'Unreadable'],
    default      => ['off',  'No file'],
};
$targetExists     = $project && is_dir($project['target_path']);
$templateHasValues = $project && ($project['env_template'] ?? '') !== '' && env_template_has_values((string) $project['env_template']);
$otherProjects    = array_values(array_filter($projects, fn($p) => (int) $p['id'] !== $projectId));
$expansion        = $project ? EnvVar::expansionRisks($projectId) : [];
$exampleExists = $project &&
    file_exists(rtrim($project['target_path'], '/\\') . DIRECTORY_SEPARATOR . '.env.example');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>.env Manager | <?= h(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/app.css">
    <style>
        .env-grid          { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 24px; }
        .env-card code     { overflow-wrap: anywhere; }
        .env-full          { grid-column: 1 / -1; }
        .env-card          { background: #111; border: 1px solid #222; border-radius: 10px; padding: 24px; }
        .env-card h3       { margin: 0 0 16px; font-size: 14px; font-weight: 600; color: #fff; }
        .key-table         { width: 100%; border-collapse: collapse; }
        .key-table th      { text-align: left; padding: 8px 10px; font-size: 11px; font-weight: 600;
                             color: #888; border-bottom: 1px solid #222; text-transform: uppercase; letter-spacing: .05em; }
        .key-table td      { padding: 8px 10px; border-bottom: 1px solid #1a1a1a; vertical-align: middle; }
        .key-table tr:last-child td { border-bottom: none; }
        .key-name          { font-family: monospace; font-size: 13px; color: #e5e7eb; font-weight: 500; }
        .val-wrap          { display: flex; gap: 6px; align-items: center; }
        .val-input         { flex: 1; font-family: monospace; font-size: 12px; background: #0a0a0a;
                             border: 1px solid #2a2a2a; border-radius: 5px; padding: 6px 10px;
                             color: #e5e7eb; outline: none; }
        .val-input:focus   { border-color: #3ecf8e; }
        .btn-eye           { background: none; border: 1px solid #333; border-radius: 5px;
                             color: #888; padding: 5px 8px; cursor: pointer; font-size: 11px; white-space: nowrap; }
        .btn-eye:hover     { border-color: #555; color: #ccc; }
        .btn-del           { background: none; border: 1px solid #333; border-radius: 5px;
                             color: #f87171; padding: 5px 8px; cursor: pointer; font-size: 11px; }
        .btn-del:hover     { background: rgba(248,113,113,.1); border-color: #f87171; }
        .req-check         { accent-color: #3ecf8e; width: 14px; height: 14px; cursor: pointer; }
        .template-area     { width: 100%; min-height: 220px; font-family: monospace; font-size: 12px;
                             background: #0a0a0a; border: 1px solid #2a2a2a; border-radius: 6px;
                             padding: 12px; color: #e5e7eb; resize: vertical; outline: none; box-sizing: border-box; }
        .template-area:focus { border-color: #3ecf8e; }
        .mode-toggle       { display: flex; align-items: center; gap: 12px; }
        .toggle-switch     { position: relative; display: inline-block; width: 42px; height: 22px; }
        .toggle-switch input { opacity: 0; width: 0; height: 0; }
        .toggle-slider     { position: absolute; inset: 0; background: #333; border-radius: 22px; cursor: pointer; transition: .2s; }
        .toggle-slider:before { content: ''; position: absolute; height: 16px; width: 16px; left: 3px; bottom: 3px;
                                background: #fff; border-radius: 50%; transition: .2s; }
        input:checked + .toggle-slider           { background: #3ecf8e; }
        input:checked + .toggle-slider:before    { transform: translateX(20px); }
        .toggle-label      { font-size: 13px; color: #ccc; }
        .add-key-row       { display: flex; gap: 8px; margin-top: 16px; }
        .add-key-input     { flex: 1; font-family: monospace; font-size: 12px; background: #0a0a0a;
                             border: 1px solid #2a2a2a; border-radius: 5px; padding: 7px 10px;
                             color: #e5e7eb; outline: none; }
        .add-key-input:focus { border-color: #3ecf8e; }
        .empty-vars        { color: #555; font-size: 13px; text-align: center; padding: 32px 0; }
        .badge-managed     { background: rgba(62,207,142,.15); color: #3ecf8e;
                             border: 1px solid rgba(62,207,142,.3); font-size: 10px;
                             padding: 2px 8px; border-radius: 20px; font-weight: 600; margin-left: 8px; }
        .badge-off         { background: rgba(100,100,100,.15); color: #666;
                             border: 1px solid #333; font-size: 10px;
                             padding: 2px 8px; border-radius: 20px; font-weight: 600; margin-left: 8px; }
        .section-hint      { font-size: 12px; color: #666; margin: 0 0 16px; line-height: 1.5; }
        .btn-row           { display: flex; gap: 8px; margin-top: 12px; flex-wrap: wrap; align-items: center; }
        .project-select    { background: #111; border: 1px solid #2a2a2a; color: #e5e7eb;
                             border-radius: 6px; padding: 7px 12px; font-size: 13px; outline: none; }
        .project-select:focus { border-color: #3ecf8e; }
        .warn-banner       { background: rgba(251,191,36,.08); border: 1px solid rgba(251,191,36,.3);
                             border-radius: 8px; padding: 12px 16px; margin-bottom: 20px;
                             font-size: 12px; color: #fbbf24; line-height: 1.6; }
        .warn-banner strong { color: #fde68a; }
        .badge-bad         { background: rgba(248,113,113,.12); color: #f87171; border: 1px solid rgba(248,113,113,.3);
                             font-size: 10px; padding: 1px 7px; border-radius: 20px; font-weight: 600; white-space: nowrap; }
        .hide-all-btn      { font-size: 11px; color: #666; background: none; border: 1px solid #333;
                             border-radius: 5px; padding: 4px 10px; cursor: pointer; margin-left: auto; }
        .hide-all-btn:hover { color: #ccc; border-color: #555; }
        .chip              { font-size: 10px; padding: 2px 8px; border-radius: 20px; font-weight: 600;
                             margin-left: 8px; border: 1px solid; vertical-align: middle; }
        .chip-ok           { background: rgba(62,207,142,.15); color: #3ecf8e; border-color: rgba(62,207,142,.3); }
        .chip-warn         { background: rgba(251,191,36,.12); color: #fbbf24; border-color: rgba(251,191,36,.3); }
        .chip-bad          { background: rgba(248,113,113,.12); color: #f87171; border-color: rgba(248,113,113,.3); }
        .chip-off          { background: rgba(100,100,100,.15); color: #888; border-color: #333; }
        .live-grid         { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 24px; margin-top: 20px;
                             padding-top: 20px; border-top: 1px solid #1e1e1e; }
        .live-form h4      { margin: 0 0 6px; font-size: 13px; font-weight: 600; color: #e5e7eb; }
        .live-note         { font-size: 12px; color: #888; line-height: 1.5; margin: 6px 0 0; }
        .drift-list        { margin: 0 0 4px; padding-left: 18px; font-size: 12px; color: #9ca3af; line-height: 1.9; }
        .drift-list code   { color: #e5e7eb; }
        .field-label       { display: block; font-size: 11px; color: #777; margin: 12px 0 4px; }
        .cmd-area          { width: 100%; min-height: 54px; font-family: monospace; font-size: 12px; background: #0a0a0a;
                             border: 1px solid #2a2a2a; border-radius: 6px; padding: 8px 10px; color: #e5e7eb;
                             resize: vertical; outline: none; box-sizing: border-box; }
        .cmd-area:focus    { border-color: #3ecf8e; }
        .check-line        { display: flex; gap: 8px; align-items: center; font-size: 12px; color: #9ca3af; margin-top: 8px; }
        .check-line input  { accent-color: #3ecf8e; }
        .text-input        { background: #0a0a0a; border: 1px solid #2a2a2a; border-radius: 5px; padding: 7px 10px;
                             color: #e5e7eb; font-size: 12px; outline: none; min-width: 0; box-sizing: border-box; }
        .text-input:focus  { border-color: #3ecf8e; }
        .table-scroll      { overflow-x: auto; }
        .filter-row        { display: flex; align-items: center; gap: 10px; margin: -4px 0 14px; }
        .filter-row .text-input { flex: 1; max-width: 320px; }
        .filter-count      { font-size: 11px; color: #666; }
        .drag-cell         { white-space: nowrap; width: 64px; }
        .drag-handle       { cursor: grab; color: #555; font-size: 14px; padding: 0 4px; user-select: none; }
        .drag-handle:hover { color: #aaa; }
        .mv                { background: none; border: none; color: #555; cursor: pointer; font-size: 9px; padding: 3px 4px; }
        .mv:hover          { color: #ccc; }
        tr.dragging        { opacity: .35; }
        .order-note        { font-size: 11px; color: #fbbf24; margin-left: 10px; }
        details.bulk-card > summary { cursor: pointer; font-size: 14px; font-weight: 600; color: #fff; list-style-position: inside; }
        details.bulk-card[open] > summary { margin-bottom: 14px; }
        .tool-row          { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-top: 10px; }
        .tool-divider      { border: 0; border-top: 1px solid #1e1e1e; margin: 20px 0 16px; }
        @media (max-width: 768px) { .env-grid, .live-grid { grid-template-columns: minmax(0, 1fr); } }    </style>
</head>
<body>

<header class="topbar">
    <div class="topbar-inner">
        <div class="topbar-brand">
            <span class="brand-icon">⚡</span>
            <span class="brand-name"><?= h(APP_NAME) ?></span>
        </div>
        <div class="topbar-actions">
            <a href="index.php"    class="btn btn-ghost btn-sm">← Dashboard</a>
            <a href="settings.php" class="btn btn-ghost btn-sm">Settings</a>
            <a href="logout.php"   class="btn btn-ghost btn-sm">Logout</a>
        </div>
    </div>
</header>

<main class="main-content">
    <div class="container">

        <!-- Flash messages -->
        <?php foreach ($flash as $f): ?>
            <div class="alert alert-<?= h($f['type']) ?>"><?= h($f['message']) ?></div>
        <?php endforeach; ?>

        <!-- SECRET_KEY warning -->
        <?php if ($noSecretKey): ?>
        <div class="warn-banner">
            <strong>Encryption warning:</strong> <code>SECRET_KEY</code> is not set in
            <code>config.php</code>. Env values are currently encrypted with a key derived
            from your login password hash. If you change your password, all stored env values
            will become permanently unreadable. Add a random <code>SECRET_KEY</code> to
            <code>config.php</code> to fix this.
        </div>
        <?php endif; ?>

        <?php if (!empty($unreadable)): ?>
        <div class="warn-banner" style="border-color:rgba(248,113,113,.4); color:#f87171;">
            <strong style="color:#fca5a5;">Cannot decrypt <?= count($unreadable) ?> stored value(s):</strong>
            <code><?= h(implode(', ', $unreadable)) ?></code>.
            The encryption key has changed or the data is corrupt. Deploys of this project are
            blocked until each is re-entered or deleted.
        </div>
        <?php endif; ?>

        <!-- Page header + project selector -->
        <div class="page-header" style="flex-wrap:wrap; gap:12px;">
            <div>
                <h1 class="page-title">
                    .env Manager
                    <?php if ($project): ?>
                        <?php if ($managed): ?>
                            <span class="badge-managed">Managed</span>
                        <?php else: ?>
                            <span class="badge-off">Off</span>
                        <?php endif; ?>
                    <?php endif; ?>
                </h1>
                <p class="page-subtitle">Securely manage environment variables per project</p>
            </div>
            <?php if (!empty($projects)): ?>
            <form method="get" style="display:flex; align-items:center; gap:10px;">
                <select name="project_id" class="project-select" onchange="this.form.submit()">
                    <option value="">— Select project —</option>
                    <?php foreach ($projects as $p): ?>
                        <option value="<?= $p['id'] ?>"<?= $p['id'] == $projectId ? ' selected' : '' ?>>
                            <?= h($p['name']) ?>
                            <?= ($p['env_mode'] ?? 'none') === 'managed' ? ' ✓' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
            <?php endif; ?>
        </div>

        <?php if (!$project): ?>
            <div class="empty-state">
                <div class="empty-icon">🔑</div>
                <h2 class="empty-title">Select a Project</h2>
                <p class="empty-text">Choose a project from the dropdown above to manage its .env variables.</p>
            </div>
        <?php else: ?>

        <?php if (!empty($expansion)): ?>
        <div class="warn-banner">
            <strong>Some values may be changed when the app reads the .env.</strong>
            <code><?= h(implode(', ', array_column($expansion, 'key'))) ?></code>
            contain a <code>${NAME}</code> reference. CodeIgniter 4's loader expands these even inside quotes
            (phpdotenv does not, because they are written single-quoted), and there is no way to escape them.
            <?php $certain = array_column(array_filter($expansion, fn($r) => $r['definite']), 'key'); ?>
            <?php if (!empty($certain)): ?>
                <br>Certain to change — they reference another managed key: <code><?= h(implode(', ', $certain)) ?></code>.
            <?php endif; ?>
            If this project runs on CodeIgniter 4, avoid <code>${</code> in those values.
        </div>
        <?php endif; ?>

        <?php if ($templateHasValues): ?>
        <div class="warn-banner">
            <strong>The stored template still contains values in plaintext.</strong>
            Earlier versions kept pasted text, values included, in the project's template
            (unencrypted in the database). Scrubbing removes them; anything the template alone
            supplied is saved encrypted first, so the deployed file does not change.
            <form method="post" class="btn-row">
                <?= $csrfField ?>
                <input type="hidden" name="form_action" value="scrub_template">
                <button type="submit" class="btn btn-primary btn-sm">Scrub template now</button>
            </form>
        </div>
        <?php endif; ?>

        <?php if ($pendingPull): ?>
        <div class="warn-banner" style="border-color:rgba(248,113,113,.4);">
            <strong style="color:#fca5a5;">The live .env holds a different value for <?= count($pendingPull['differs']) ?> key(s) that already have a stored value — nothing has been imported yet.</strong><br>
            <code><?= h(implode(', ', $pendingPull['differs'])) ?></code>
            <span style="color:#a3a3a3;">(values are not shown)</span>
            <form method="post" class="btn-row">
                <?= $csrfField ?>
                <input type="hidden" name="form_action" value="pull_live">
                <button type="submit" name="pull_decision" value="overwrite" class="btn btn-danger btn-sm">Replace with live values</button>
                <button type="submit" name="pull_decision" value="fill" class="btn btn-primary btn-sm">Keep my stored values, import the rest</button>
                <a href="env_manager.php?project_id=<?= (int) $projectId ?>" class="btn btn-ghost btn-sm">Cancel</a>
            </form>
        </div>
        <?php endif; ?>

        <?php if ($pendingRemoval): ?>
        <div class="warn-banner" style="border-color:rgba(248,113,113,.4);">
            <strong style="color:#fca5a5;">This template drops <?= count($pendingRemoval['removed']) ?> existing key(s) — nothing has been saved yet.</strong><br>
            <code><?= h(implode(', ', $pendingRemoval['removed'])) ?></code>
            <?php if (!empty($pendingRemoval['with_value'])): ?>
                <br><span style="color:#f87171;"><?= count($pendingRemoval['with_value']) ?> of these hold stored values that would be permanently deleted:
                <code><?= h(implode(', ', $pendingRemoval['with_value'])) ?></code></span>
            <?php endif; ?>
            <form method="post" class="btn-row">
                <?= $csrfField ?>
                <input type="hidden" name="form_action" value="save_template">
                <input type="hidden" name="env_template" value="<?= h($pendingRemoval['template']) ?>">
                <button type="submit" name="removal" value="remove" class="btn btn-danger btn-sm">Save and remove these keys</button>
                <button type="submit" name="removal" value="keep" class="btn btn-primary btn-sm">Save and keep them</button>
                <a href="env_manager.php?project_id=<?= (int) $projectId ?>" class="btn btn-ghost btn-sm">Cancel</a>
            </form>
        </div>
        <?php endif; ?>
        <div class="env-grid">

            <!-- ================================================================
                 CARD 1 — Enable / Disable
            ================================================================ -->
            <div class="env-card">
                <h3>Managed .env</h3>
                <p class="section-hint">
                    When enabled, a <code>.env</code> file is written automatically to the
                    project's target directory on every deploy. Values are stored encrypted
                    in the database and never appear in deploy logs.
                </p>
                <form method="post">
                    <?= $csrfField ?>
                    <input type="hidden" name="form_action" value="toggle_mode">
                    <div class="mode-toggle">
                        <label class="toggle-switch">
                            <input type="checkbox" name="env_mode" value="managed"
                                   onchange="this.form.submit()"
                                <?= $managed ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                        </label>
                        <span class="toggle-label">
                            <?= $managed ? 'Enabled — .env is written on every deploy' : 'Disabled — .env is managed manually' ?>
                        </span>
                    </div>
                </form>
            </div>

            <!-- ================================================================
                 CARD 2 — Template editor
                 Note: save-template and import-example are SEPARATE forms so they
                 are never nested. The import button is placed after the save form.
            ================================================================ -->
            <div class="env-card">
                <h3>Template <span style="color:#555; font-weight:400;">(.env structure)</span></h3>
                <p class="section-hint">
                    Paste your <code>.env.example</code> or a full <code>.env</code> here.
                    Saving extracts every <code>KEY=value</code> line: values go into the encrypted
                    store and are then blanked out of this template, which keeps only the structure
                    (keys, order, comments) used to write the file at deploy time.
                </p>

                <form method="post" id="save-tmpl-form">
                    <?= $csrfField ?>
                    <input type="hidden" name="form_action" value="save_template">
                    <textarea
                        name="env_template"
                        class="template-area"
                        placeholder="# App&#10;APP_NAME=My App&#10;APP_ENV=production&#10;APP_DEBUG=false&#10;&#10;# Database&#10;DB_HOST=localhost&#10;DB_NAME=&#10;DB_USER=&#10;DB_PASS="
                    ><?= h($template) ?></textarea>
                    <div class="btn-row">
                        <button type="submit" class="btn btn-primary btn-sm">Save Template</button>
                    </div>
                </form>

                <?php if ($exampleExists): ?>
                <form method="post" style="margin-top:8px;">
                    <?= $csrfField ?>
                    <input type="hidden" name="form_action" value="import_example">
                    <button type="submit" class="btn btn-ghost btn-sm"
                            title="Read .env.example from target directory and import keys + default values">
                        ↓ Import from .env.example
                    </button>
                </form>
                <?php else: ?>
                <p style="font-size:11px; color:#555; margin-top:8px;">(No .env.example found in target directory)</p>
                <?php endif; ?>
            </div>

            <!-- ================================================================
                 CARD — Live .env: drift check, apply now, pull from live
            ================================================================ -->
            <div class="env-card env-full">
                <h3>Live .env <span class="chip chip-<?= h($liveChip[0]) ?>"><?= h($liveChip[1]) ?></span></h3>
                <p class="section-hint">
                    Compares <code><?= h($live['path']) ?></code> with what the manager would write.
                    Only key names are shown — never values.
                </p>

                <?php if ($live['state'] === 'no_file'): ?>
                    <p class="live-note">No <code>.env</code> in the target directory yet. It is created on the next deploy, or with <em>Apply now</em>.</p>
                <?php elseif ($live['state'] === 'unreadable'): ?>
                    <p class="live-note">The file exists but the web server cannot read it — check its owner and permissions.</p>
                <?php elseif ($live['state'] === 'in_sync'): ?>
                    <p class="live-note">Matches what the manager would write. Last modified <?= h(time_ago(date('Y-m-d H:i:s', (int) $live['mtime']))) ?>.</p>
                <?php else: ?>
                    <ul class="drift-list">
                        <?php if ($live['changed']): ?>
                            <li><strong>Different value:</strong> <code><?= h(implode(', ', $live['changed'])) ?></code></li>
                        <?php endif; ?>
                        <?php if ($live['missing']): ?>
                            <li><strong>Managed, but not in the file:</strong> <code><?= h(implode(', ', $live['missing'])) ?></code></li>
                        <?php endif; ?>
                        <?php if ($live['extra']): ?>
                            <li><strong>In the file, but not managed:</strong> <code><?= h(implode(', ', $live['extra'])) ?></code>
                                — <em>Pull from live</em> can adopt them.</li>
                        <?php endif; ?>
                    </ul>
                    <p class="live-note">Last modified <?= h(time_ago(date('Y-m-d H:i:s', (int) $live['mtime']))) ?>.</p>
                <?php endif; ?>

                <div class="live-grid">
                    <form method="post" class="live-form">
                        <?= $csrfField ?>
                        <h4>Apply now</h4>
                        <p class="section-hint" style="margin-bottom:0;">
                            Writes the managed .env to the target immediately, without redeploying. Recorded
                            in Logs like a deploy, and refused while a deploy is running.
                        </p>
                        <label class="field-label" for="env_apply_cmd">Then run (optional — one command per line)</label>
                        <textarea id="env_apply_cmd" name="env_apply_cmd" class="cmd-area" rows="2"
                                  placeholder="php artisan config:cache"><?= h($project['env_apply_cmd'] ?? '') ?></textarea>
                        <label class="check-line">
                            <input type="checkbox" name="run_cmd" value="1"
                                   <?= trim((string) ($project['env_apply_cmd'] ?? '')) !== '' ? 'checked' : '' ?>>
                            Run the command after writing
                        </label>
                        <div class="btn-row">
                            <button type="submit" name="form_action" value="apply_now" class="btn btn-primary btn-sm"
                                    <?= (!$managed || !$targetExists) ? 'disabled' : '' ?>>Apply now</button>
                            <button type="submit" name="form_action" value="save_apply_cmd" class="btn btn-ghost btn-sm">Save command</button>
                        </div>
                        <?php if (!$managed): ?>
                            <p class="live-note">Turn on <em>Managed .env</em> above to use Apply now.</p>
                        <?php elseif (!$targetExists): ?>
                            <p class="live-note">The target directory doesn't exist yet — run a deploy first.</p>
                        <?php endif; ?>
                    </form>

                    <form method="post" class="live-form">
                        <?= $csrfField ?>
                        <input type="hidden" name="form_action" value="pull_live">
                        <h4>Pull from live</h4>
                        <p class="section-hint" style="margin-bottom:0;">
                            Imports values from the target's current <code>.env</code> — the quickest way to bring an
                            existing site under management. Nothing is deleted: new keys are added and empty values
                            filled in. If the manager already holds a different value, you're asked first.
                        </p>
                        <div class="btn-row">
                            <button type="submit" class="btn btn-ghost btn-sm"
                                    <?= in_array($live['state'], ['no_file', 'unreadable'], true) ? 'disabled' : '' ?>>↓ Pull from live .env</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- ================================================================
                 CARD 3 — Values editor (full width)
                 The shared delete-key form lives outside the values form to avoid
                 nested <form> elements (invalid HTML). JS submits it on confirm.
            ================================================================ -->

            <!-- Shared delete form — submitted by confirmDelete() -->
            <form method="post" id="del-key-form" style="display:none;">
                <?= $csrfField ?>
                <input type="hidden" name="form_action" value="delete_key">
                <input type="hidden" name="del_key" id="del-key-name" value="">
            </form>

            <!-- Shared rename form — submitted by renameKey() -->
            <form method="post" id="rename-key-form" style="display:none;">
                <?= $csrfField ?>
                <input type="hidden" name="form_action" value="rename_key">
                <input type="hidden" name="old_key" id="rename-old-key" value="">
                <input type="hidden" name="new_key" id="rename-new-key" value="">
            </form>

            <div class="env-card env-full">
                <h3>
                    Values
                    <span style="color:#555; font-weight:400; font-size:12px; margin-left:6px;">
                        <?= count($envVars) ?> key<?= count($envVars) !== 1 ? 's' : '' ?>
                    </span>
                </h3>

                <?php if (!empty($envVars)): ?>
                <div class="filter-row">
                    <input type="search" id="key-filter" class="text-input" placeholder="Filter keys…"
                           autocomplete="off" aria-label="Filter keys">
                    <span class="filter-count" id="filter-count"></span>
                </div>
                <?php endif; ?>

                <?php if (empty($envVars)): ?>
                    <p class="empty-vars">
                        No keys yet. Save a template above or add individual keys below.
                    </p>
                <?php else: ?>
                <form method="post" id="values-form">
                    <?= $csrfField ?>
                    <input type="hidden" name="form_action" value="save_values">
                    <div class="table-scroll">
                    <table class="key-table">
                        <thead>
                            <tr>
                                <th style="width:64px"><span class="sr-only" style="position:absolute;left:-9999px;">Order</span></th>
                                <th style="width:30%">Key</th>
                                <th>Value</th>
                                <th style="width:60px; text-align:center;">Req.</th>
                                <th style="width:80px;">
                                    <button type="button" class="hide-all-btn" id="toggle-all-btn"
                                            onclick="toggleAllVals(this)">Show all</button>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($envVars as $var): ?>
                            <tr data-key="<?= h($var['key_name']) ?>">
                                <td class="drag-cell">
                                    <input type="hidden" name="env_order[]" value="<?= h($var['key_name']) ?>">
                                    <span class="drag-handle" title="Drag to reorder" aria-hidden="true">⋮⋮</span>
                                    <button type="button" class="mv" onclick="moveRow(this,-1)" title="Move up" aria-label="Move <?= h($var['key_name']) ?> up">▲</button>
                                    <button type="button" class="mv" onclick="moveRow(this,1)" title="Move down" aria-label="Move <?= h($var['key_name']) ?> down">▼</button>
                                </td>
                                <td>
                                    <div style="display:flex; align-items:center; gap:6px;">
                                        <span class="key-name"><?= h($var['key_name']) ?></span>
                                        <?php if ($var['decrypt_failed']): ?>
                                            <span class="badge-bad" title="Stored value cannot be decrypted — SECRET_KEY changed or data is corrupt. Deploys are blocked until it is re-entered or deleted.">⚠ unreadable</span>
                                        <?php endif; ?>
                                        <button type="button"
                                                onclick="renameKey(<?= json_encode($var['key_name']) ?>)"
                                                title="Rename key"
                                                style="background:none; border:none; color:#555; cursor:pointer;
                                                       font-size:11px; padding:0; line-height:1;"
                                                >✎</button>
                                    </div>
                                </td>
                                <td>
                                    <?php $multi = strpbrk($var['value'], "\r\n") !== false; ?>
                                    <div class="val-wrap">
                                        <?php if ($multi): ?>
                                            <input type="hidden" name="env_escaped[<?= h($var['key_name']) ?>]" value="1">
                                        <?php endif; ?>
                                        <input
                                            type="password"
                                            class="val-input"
                                            name="env_values[<?= h($var['key_name']) ?>]"
                                            value="<?= h($multi ? env_escape_lines($var['value']) : $var['value']) ?>"
                                            <?= $multi ? 'data-escaped="1" title="Contains line breaks, shown as \\n"' : '' ?>
                                            autocomplete="new-password"
                                            placeholder="<?= $var['decrypt_failed'] ? '(cannot decrypt — re-enter)' : '(empty)' ?>"
                                        >
                                        <button type="button" class="btn-eye"
                                                onclick="toggleVal(this)">Show</button>
                                    </div>
                                </td>
                                <td style="text-align:center;">
                                    <input
                                        type="checkbox"
                                        class="req-check"
                                        name="env_required[<?= h($var['key_name']) ?>]"
                                        value="1"
                                        title="Required — deploy halts if this key has no value"
                                        <?= $var['is_required'] ? 'checked' : '' ?>
                                    >
                                </td>
                                <td>
                                    <button type="button" class="btn-del"
                                            onclick="confirmDelete(<?= json_encode($var['key_name']) ?>)"
                                            title="Delete key and its value permanently">✕</button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                    <div class="btn-row" style="margin-top:20px;">
                        <button type="submit" class="btn btn-primary btn-sm">Save Values</button>
                        <span class="order-note" id="order-note" hidden>Order changed — save to keep it (the template is reordered to match).</span>
                    </div>
                </form>
                <?php endif; ?>

                <!-- Add key row — its own form, always visible -->
                <form method="post" class="add-key-row">
                    <?= $csrfField ?>
                    <input type="hidden" name="form_action" value="add_key">
                    <input type="text" name="new_key" class="add-key-input"
                           placeholder="new_key_name or NEW_KEY"
                           title="Any key without spaces, # or =">
                    <button type="submit" class="btn btn-ghost btn-sm">+ Add Key</button>
                </form>
            </div>

            <!-- ================================================================
                 CARD — Bulk edit as text
            ================================================================ -->
            <?php if (!empty($envVars) || $managed): ?>
            <details class="env-card env-full bulk-card" id="bulk-card">
                <summary>Bulk edit as text</summary>
                <p class="section-hint">
                    Edit every value as <code>KEY=value</code> lines — handy for pasting many changes at once.
                    Listed keys are updated (an empty value clears it) and new keys are added; keys you leave
                    out are not touched. Values are shown in plain text while this panel is open.
                </p>
                <form method="post">
                    <?= $csrfField ?>
                    <input type="hidden" name="form_action" value="bulk_edit">
                    <textarea name="bulk_text" id="bulk-text" class="template-area" spellcheck="false"
                              style="min-height:260px;" aria-label="Environment variables as KEY=value text"></textarea>
                    <div class="btn-row">
                        <button type="submit" class="btn btn-primary btn-sm">Apply bulk edit</button>
                        <button type="button" class="btn btn-ghost btn-sm" onclick="loadBulk(true)">Reload from table</button>
                    </div>
                </form>
            </details>
            <?php endif; ?>

            <!-- ================================================================
                 CARDS — Copy from project / encrypted export & import
            ================================================================ -->
            <div class="env-card">
                <h3>Copy from another project</h3>
                <p class="section-hint">
                    Brings another project's keys, values, required flags and structure in. Keys you already
                    have a value for are kept unless you tick overwrite; nothing is deleted.
                </p>
                <?php if (empty($otherProjects)): ?>
                    <p class="live-note">There are no other projects to copy from.</p>
                <?php else: ?>
                <form method="post">
                    <?= $csrfField ?>
                    <input type="hidden" name="form_action" value="copy_from">
                    <div class="tool-row" style="margin-top:0;">
                        <select name="source_project" class="project-select" aria-label="Project to copy from" required>
                            <option value="">— Select project —</option>
                            <?php foreach ($otherProjects as $op): ?>
                                <option value="<?= (int) $op['id'] ?>"><?= h($op['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-ghost btn-sm">Copy</button>
                    </div>
                    <label class="check-line">
                        <input type="checkbox" name="overwrite" value="1"> Overwrite values I already have
                    </label>
                </form>
                <?php endif; ?>
            </div>

            <div class="env-card">
                <h3>Encrypted export / import</h3>
                <p class="section-hint">
                    A passphrase-protected file (AES-256-GCM) holding this project's keys, values and structure —
                    for backups or moving to another install. The passphrase is the only key: lose it and the file
                    can't be opened.
                </p>
                <form method="post">
                    <?= $csrfField ?>
                    <input type="hidden" name="form_action" value="export_bundle">
                    <div class="tool-row" style="margin-top:0;">
                        <input type="password" name="passphrase" class="text-input" minlength="10" required
                               placeholder="Passphrase (10+ characters)" autocomplete="new-password" aria-label="Export passphrase">
                        <button type="submit" class="btn btn-ghost btn-sm">↓ Export</button>
                    </div>
                </form>
                <hr class="tool-divider">
                <form method="post" enctype="multipart/form-data">
                    <?= $csrfField ?>
                    <input type="hidden" name="form_action" value="import_bundle">
                    <input type="file" name="bundle" accept=".json,application/json" required aria-label="Bundle file"
                           style="font-size:12px; color:#9ca3af; max-width:100%;">
                    <div class="tool-row">
                        <input type="password" name="passphrase" class="text-input" required
                               placeholder="Passphrase" autocomplete="off" aria-label="Import passphrase">
                        <button type="submit" class="btn btn-ghost btn-sm">↑ Import</button>
                    </div>
                    <label class="check-line">
                        <input type="checkbox" name="overwrite" value="1"> Overwrite values I already have
                    </label>
                </form>
            </div>

            <!-- ================================================================
                 CARD 4 — Preview (full width, only if managed + has vars)
            ================================================================ -->
            <?php if ($managed && !empty($envVars)): ?>
            <div class="env-card env-full">
                <h3>Preview <span style="color:#555; font-weight:400; font-size:12px; margin-left:6px;">rendered .env that will be written on next deploy</span></h3>
                <?php
                    $values = [];
                    foreach ($envVars as $v) { $values[$v['key_name']] = $v['value']; }
                    $preview = !empty($template)
                        ? env_render($template, $values)
                        : implode("\n", array_map(
                            fn($v) => $v['key_name'] . '=' . ($v['value'] !== '' ? '***' : ''),
                            $envVars
                          ));
                    // Mask all non-empty values regardless of key format
                    $previewMasked = preg_replace_callback(
                        '/^((?:export\s+)?\S+=)(.+)$/m',
                        fn($m) => $m[1] . '***',
                        $preview
                    );
                ?>
                <pre style="background:#0a0a0a; border:1px solid #1e1e1e; border-radius:6px; padding:16px;
                            font-size:12px; color:#9ca3af; white-space:pre-wrap; margin:0; overflow-x:auto;"><?= h($previewMasked) ?></pre>
                <p style="font-size:11px; color:#555; margin:8px 0 0;">Values are masked. The actual file written to target will contain real values.</p>
            </div>
            <?php endif; ?>

        </div><!-- /env-grid -->

        <?php endif; // $project ?>

    </div>
</main>

<script>
// Toggle a single value field between visible (text) and hidden (password)
function toggleVal(btn) {
    const input = btn.previousElementSibling;
    if (!input) return;
    const showing = input.type === 'text';
    input.type       = showing ? 'password' : 'text';
    btn.textContent  = showing ? 'Show' : 'Hide';
}

// Toggle ALL value fields at once
function toggleAllVals(btn) {
    const inputs = document.querySelectorAll('#values-form .val-input');
    const btns   = document.querySelectorAll('#values-form .btn-eye');
    const hiding = btn.textContent.trim() === 'Hide all';
    inputs.forEach(inp => { inp.type = hiding ? 'password' : 'text'; });
    btns.forEach(b   => { b.textContent = hiding ? 'Show' : 'Hide'; });
    btn.textContent = hiding ? 'Show all' : 'Hide all';
}

// Delete a key — submits the shared del-key-form after confirmation
function confirmDelete(key) {
    if (!confirm('Delete key "' + key + '"?\n\nThis permanently removes the key and its encrypted value.')) return;
    document.getElementById('del-key-name').value = key;
    document.getElementById('del-key-form').submit();
}

// Rename a key — prompts for new name then submits the shared rename-key-form
function renameKey(oldKey) {
    const newKey = prompt('Rename "' + oldKey + '" to:', oldKey);
    if (!newKey || newKey.trim() === '' || newKey.trim() === oldKey) return;
    document.getElementById('rename-old-key').value = oldKey;
    document.getElementById('rename-new-key').value = newKey.trim();
    document.getElementById('rename-key-form').submit();
}

// ---- Filter keys --------------------------------------------------------------
(function () {
    const input = document.getElementById('key-filter');
    if (!input) return;
    const rows  = Array.from(document.querySelectorAll('#values-form tbody tr'));
    const count = document.getElementById('filter-count');
    input.addEventListener('input', function () {
        const q = input.value.trim().toLowerCase();
        let shown = 0;
        rows.forEach(tr => {
            const match = !q || (tr.dataset.key || '').toLowerCase().includes(q);
            tr.hidden = !match;
            if (match) shown++;
        });
        count.textContent = q ? shown + ' of ' + rows.length : '';
    });
})();

// ---- Reorder rows (buttons work everywhere; drag works with a mouse) ---------
function noteOrderChanged() {
    const n = document.getElementById('order-note');
    if (n) n.hidden = false;
}

function moveRow(btn, dir) {
    const row = btn.closest('tr');
    let sib = dir < 0 ? row.previousElementSibling : row.nextElementSibling;
    while (sib && sib.hidden) sib = dir < 0 ? sib.previousElementSibling : sib.nextElementSibling;
    if (!sib) return;
    row.parentNode.insertBefore(row, dir < 0 ? sib : sib.nextSibling);
    noteOrderChanged();
    btn.focus();
}

(function () {
    const body = document.querySelector('#values-form tbody');
    if (!body) return;
    let dragging = null;

    // Only start a drag from the handle, so text in the value inputs stays selectable
    body.addEventListener('mousedown', e => {
        const tr = e.target.closest('tr');
        if (tr) tr.draggable = !!e.target.closest('.drag-handle');
    });
    document.addEventListener('mouseup', () => body.querySelectorAll('tr[draggable="true"]').forEach(tr => { tr.draggable = false; }));

    body.addEventListener('dragstart', e => {
        dragging = e.target.closest('tr');
        if (!dragging) return;
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', dragging.dataset.key || '');
        dragging.classList.add('dragging');
    });
    body.addEventListener('dragover', e => {
        if (!dragging) return;
        e.preventDefault();
        const over = e.target.closest('tr');
        if (!over || over === dragging || over.hidden) return;
        const r = over.getBoundingClientRect();
        over.parentNode.insertBefore(dragging, (e.clientY - r.top) > r.height / 2 ? over.nextSibling : over);
    });
    body.addEventListener('dragend', () => {
        if (!dragging) return;
        dragging.classList.remove('dragging');
        dragging.draggable = false;
        dragging = null;
        noteOrderChanged();
    });
})();

// ---- Bulk edit: build KEY=value text from the table when the panel opens -------
function bulkQuote(v) {
    if (v === '') return '';
    if (/^[A-Za-z0-9_@%+=:,.\/-]+$/.test(v)) return v;
    return '"' + v.replace(/\\/g, '\\\\').replace(/"/g, '\\"')
                  .replace(/\r/g, '\\r').replace(/\n/g, '\\n').replace(/\t/g, '\\t') + '"';
}

function loadBulk(force) {
    const area = document.getElementById('bulk-text');
    if (!area || (!force && area.dataset.dirty === '1')) return;
    const lines = [];
    document.querySelectorAll('#values-form tbody tr').forEach(tr => {
        const input = tr.querySelector('.val-input');
        if (!input) return;
        // Flagged rows show line breaks as \n escapes; decode to the real value first
        const v = input.dataset.escaped === '1'
            ? input.value.replace(/\\(\\|n|r)/g, (m, c) => c === 'n' ? '\n' : c === 'r' ? '\r' : '\\')
            : input.value;
        lines.push(tr.dataset.key + '=' + bulkQuote(v));
    });
    area.value = lines.join('\n') + (lines.length ? '\n' : '');
    area.dataset.dirty = '0';
}

(function () {
    const card = document.getElementById('bulk-card');
    const area = document.getElementById('bulk-text');
    if (!card || !area) return;
    area.addEventListener('input', () => { area.dataset.dirty = '1'; });
    card.addEventListener('toggle', () => { if (card.open) loadBulk(false); });
})();
</script>
</body>
</html>
