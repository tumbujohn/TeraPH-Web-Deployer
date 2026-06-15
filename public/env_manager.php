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

    // ---- Save template ------------------------------------------------------
    // Parses both keys AND values from the template text.
    // Keys are synced (removed if no longer present). Non-empty values are
    // upserted so pasting a full .env captures everything in one action.
    if ($postAction === 'save_template') {
        $template     = $_POST['env_template'] ?? '';
        $pairs        = env_parse_pairs($template);
        $keys         = array_keys($pairs);
        $existingKeys = array_column(EnvVar::allForProject($projectId), 'key_name');
        $removedKeys  = array_values(array_diff($existingKeys, $keys));

        $pdo = Database::connect();
        $pdo->prepare("UPDATE projects SET env_template = ?, updated_at = ? WHERE id = ?")
            ->execute([$template ?: null, date('Y-m-d H:i:s'), $projectId]);
        $project['env_template'] = $template ?: null;

        EnvVar::syncKeys($projectId, $keys);

        // Import non-empty values found in the template text
        foreach ($pairs as $key => $value) {
            if ($value !== '') {
                $sortOrder = (int) array_search($key, $keys, true);
                EnvVar::upsert($projectId, $key, $value, false, $sortOrder);
            }
        }

        $msg = 'Template saved. ' . count($keys) . ' key(s) synced.';
        if (!empty($removedKeys)) {
            $msg .= ' Removed: ' . implode(', ', $removedKeys) . '.';
        }
        flash('success', $msg);
        header('Location: env_manager.php?project_id=' . $projectId);
        exit;
    }

    // ---- Import .env.example from target ------------------------------------
    // Reads the file and imports both keys and any default values it contains.
    if ($postAction === 'import_example') {
        $examplePath = rtrim($project['target_path'], '/\\') . DIRECTORY_SEPARATOR . '.env.example';
        if (!file_exists($examplePath)) {
            flash('error', '.env.example not found at: ' . $examplePath);
        } else {
            $template     = (string) file_get_contents($examplePath);
            $pairs        = env_parse_pairs($template);
            $keys         = array_keys($pairs);
            $existingKeys = array_column(EnvVar::allForProject($projectId), 'key_name');
            $removedKeys  = array_values(array_diff($existingKeys, $keys));

            $pdo = Database::connect();
            $pdo->prepare("UPDATE projects SET env_template = ?, updated_at = ? WHERE id = ?")
                ->execute([$template, date('Y-m-d H:i:s'), $projectId]);
            $project['env_template'] = $template;

            EnvVar::syncKeys($projectId, $keys);

            foreach ($pairs as $key => $value) {
                if ($value !== '') {
                    $sortOrder = (int) array_search($key, $keys, true);
                    EnvVar::upsert($projectId, $key, $value, false, $sortOrder);
                }
            }

            $msg = 'Imported .env.example — ' . count($keys) . ' key(s) synced.';
            if (!empty($removedKeys)) {
                $msg .= ' Removed: ' . implode(', ', $removedKeys) . '.';
            }
            flash('success', $msg);
        }
        header('Location: env_manager.php?project_id=' . $projectId);
        exit;
    }

    // ---- Save values --------------------------------------------------------
    // Values are shown in plain text fields — what you submit is what is stored.
    // Submitting an empty field intentionally clears the value.
    if ($postAction === 'save_values') {
        $submitted = $_POST['env_values'] ?? [];
        $required  = $_POST['env_required'] ?? [];
        $existing  = EnvVar::allForProject($projectId);

        foreach ($existing as $i => $var) {
            $key        = $var['key_name'];
            $newValue   = $submitted[$key] ?? '';
            $isRequired = isset($required[$key]);
            EnvVar::upsert($projectId, $key, $newValue, $isRequired, $i);
        }
        flash('success', 'Values saved.');
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
                $order = count($existing);
                EnvVar::upsert($projectId, $newKey, '', false, $order);
                // Append key stub to template so structure stays in sync
                if (!empty($project['env_template'])) {
                    $tpl = rtrim($project['env_template']) . "\n" . $newKey . "=\n";
                    $pdo = Database::connect();
                    $pdo->prepare("UPDATE projects SET env_template = ?, updated_at = ? WHERE id = ?")
                        ->execute([$tpl, date('Y-m-d H:i:s'), $projectId]);
                }
                flash('success', "Key '{$newKey}' added.");
            }
        }
        header('Location: env_manager.php?project_id=' . $projectId);
        exit;
    }

    // ---- Delete key ---------------------------------------------------------
    if ($postAction === 'delete_key') {
        $delKey = $_POST['del_key'] ?? '';
        if ($delKey !== '') {
            $pdo = Database::connect();
            $pdo->prepare("DELETE FROM project_env_vars WHERE project_id = ? AND key_name = ?")
                ->execute([$projectId, $delKey]);
            flash('success', "Key '{$delKey}' removed.");
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
$managed      = $envMode === 'managed';
$csrfField    = csrf_field();
$noSecretKey  = !defined('SECRET_KEY') || SECRET_KEY === '';
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
        .env-grid          { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
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
        .hide-all-btn      { font-size: 11px; color: #666; background: none; border: 1px solid #333;
                             border-radius: 5px; padding: 4px 10px; cursor: pointer; margin-left: auto; }
        .hide-all-btn:hover { color: #ccc; border-color: #555; }
        @media (max-width: 768px) { .env-grid { grid-template-columns: 1fr; } }
    </style>
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
        <?php foreach (get_flash() as $f): ?>
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
                    Saving extracts all <code>KEY=value</code> lines — values are stored
                    encrypted and comments are preserved in the rendered output at deploy time.
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

            <div class="env-card env-full">
                <h3>
                    Values
                    <span style="color:#555; font-weight:400; font-size:12px; margin-left:6px;">
                        <?= count($envVars) ?> key<?= count($envVars) !== 1 ? 's' : '' ?>
                    </span>
                </h3>

                <?php if (empty($envVars)): ?>
                    <p class="empty-vars">
                        No keys yet. Save a template above or add individual keys below.
                    </p>
                <?php else: ?>
                <form method="post" id="values-form">
                    <?= $csrfField ?>
                    <input type="hidden" name="form_action" value="save_values">
                    <table class="key-table">
                        <thead>
                            <tr>
                                <th style="width:28%">Key</th>
                                <th>Value</th>
                                <th style="width:60px; text-align:center;">Req.</th>
                                <th style="width:80px;">
                                    <button type="button" class="hide-all-btn" id="toggle-all-btn"
                                            onclick="toggleAllVals(this)">Hide all</button>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($envVars as $var): ?>
                            <tr>
                                <td>
                                    <span class="key-name"><?= h($var['key_name']) ?></span>
                                </td>
                                <td>
                                    <div class="val-wrap">
                                        <input
                                            type="text"
                                            class="val-input"
                                            name="env_values[<?= h($var['key_name']) ?>]"
                                            value="<?= h($var['value']) ?>"
                                            autocomplete="off"
                                            placeholder="(empty)"
                                        >
                                        <button type="button" class="btn-eye"
                                                onclick="toggleVal(this)">Hide</button>
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
                    <div class="btn-row" style="margin-top:20px;">
                        <button type="submit" class="btn btn-primary btn-sm">Save Values</button>
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
</script>
</body>
</html>
