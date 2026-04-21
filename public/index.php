<?php
// =============================================================================
// Main Dashboard
// =============================================================================
require_once __DIR__ . '/../app/helpers.php';
app_boot();

Auth::require();

$projects = Project::all();
$csrf     = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h(APP_NAME) ?> — Dashboard</title>
    <meta name="description" content="Manage and trigger deployments for your registered web projects.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>

<!-- ======================================================================= -->
<!-- TOP BAR                                                                  -->
<!-- ======================================================================= -->
<header class="topbar">
    <div class="topbar-inner">
        <div class="topbar-brand">
            <span class="brand-icon">⚡</span>
            <span class="brand-name"><?= h(APP_NAME) ?></span>
        </div>
        <div class="topbar-actions">
            <span class="topbar-user">
                <span class="user-dot"></span>
                <?= h(Auth::user()) ?>
            </span>
            <button id="btn-add-project" class="btn btn-ghost btn-sm">+ Add Project</button>
            <a href="logout.php" class="btn btn-ghost btn-sm">Logout</a>
        </div>
    </div>
</header>

<!-- ======================================================================= -->
<!-- MAIN CONTENT                                                             -->
<!-- ======================================================================= -->
<main class="main-content">
    <div class="container">

        <!-- Flash messages -->
        <?php foreach (get_flash() as $flash): ?>
            <div class="alert alert-<?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
        <?php endforeach; ?>

        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h1 class="page-title">Projects</h1>
                <p class="page-subtitle"><?= count($projects) ?> registered project<?= count($projects) !== 1 ? 's' : '' ?></p>
            </div>
        </div>

        <!-- Project Grid -->
        <?php if (empty($projects)): ?>
            <div class="empty-state">
                <div class="empty-icon">📦</div>
                <h2 class="empty-title">No Projects Yet</h2>
                <p class="empty-text">Add your first project to start deploying.</p>
                <button class="btn btn-primary" id="btn-add-project-empty">+ Add Project</button>
            </div>
        <?php else: ?>
            <div class="project-grid" id="project-grid">
                <?php foreach ($projects as $project): ?>
                    <?php
                        $lastStatus   = $project['last_status'] ?? '';
                        $lastDeployed = $project['last_deployed_at'] ?? null;
                    ?>
                    <div class="project-card" data-project-id="<?= $project['id'] ?>" id="project-card-<?= $project['id'] ?>">
                        <div class="project-card-header">
                            <div>
                                <h2 class="project-name"><?= h($project['name']) ?></h2>
                                <?php if (($project['source_type'] ?? 'github') === 'manual'): ?>
                                    <span class="project-branch">📦 Manual Upload</span>
                                <?php else: ?>
                                    <span class="project-branch">⎇ <?= h($project['branch']) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="status-badge <?= h(status_class($lastStatus)) ?>">
                                <?= h(status_label($lastStatus)) ?>
                            </div>
                        </div>

                        <div class="project-meta">
                            <div class="meta-item">
                                <span class="meta-label">Last Deploy</span>
                                <span class="meta-value"><?= h(time_ago($lastDeployed)) ?></span>
                            </div>
                            <div class="meta-item">
                                <span class="meta-label">Target</span>
                                <span class="meta-value mono"><?= h(basename($project['target_path'])) ?></span>
                            </div>
                        </div>

                        <div class="project-actions">
                            <div class="deploy-group">
                                <button
                                    class="btn btn-primary btn-deploy"
                                    data-project-id="<?= $project['id'] ?>"
                                    data-project-name="<?= h($project['name']) ?>"
                                    data-source-type="<?= h($project['source_type'] ?? 'github') ?>"
                                >
                                    ▶ Deploy
                                </button>
                                <div class="mode-select-wrapper">
                                    <select class="mode-select" data-project-id="<?= $project['id'] ?>">
                                        <option value="safe" selected>Safe</option>
                                        <option value="full">Full</option>
                                    </select>
                                </div>
                            </div>

                            <div class="secondary-actions">
                                <button
                                    class="btn btn-ghost btn-sm btn-logs"
                                    data-project-id="<?= $project['id'] ?>"
                                    data-project-name="<?= h($project['name']) ?>"
                                >
                                    Logs
                                </button>
                                <button
                                    class="btn btn-ghost btn-sm btn-terminal"
                                    data-project-id="<?= $project['id'] ?>"
                                    data-project-name="<?= h($project['name']) ?>"
                                >
                                    >_ Terminal
                                </button>
                                <button
                                    class="btn btn-ghost btn-sm btn-backups"
                                    data-project-id="<?= $project['id'] ?>"
                                    data-project-name="<?= h($project['name']) ?>"
                                >
                                    Backups
                                </button>
                                <button
                                    class="btn btn-icon btn-edit"
                                    data-project-id="<?= $project['id'] ?>"
                                    title="Edit project"
                                >✎</button>
                                <button
                                    class="btn btn-icon btn-delete"
                                    data-project-id="<?= $project['id'] ?>"
                                    data-project-name="<?= h($project['name']) ?>"
                                    title="Delete project"
                                >✕</button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</main>

<!-- ======================================================================= -->
<!-- DEPLOY CONFIRMATION MODAL                                                -->
<!-- ======================================================================= -->
<div class="modal-overlay" id="modal-deploy" hidden>
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title">Confirm Deployment</h3>
            <button class="modal-close" data-close="modal-deploy">✕</button>
        </div>
        <div class="modal-body">
            <p>You are about to deploy:</p>
            <div class="confirm-detail">
                <span class="confirm-label">Project</span>
                <strong id="confirm-project-name">—</strong>
            </div>
            <div class="confirm-detail">
                <span class="confirm-label">Mode</span>
                <strong id="confirm-mode">Safe</strong>
            </div>

            <div class="alert alert-danger" id="full-deploy-warning" hidden>
                ⚠ <strong>Full Deploy</strong> will permanently delete all existing files in the target directory, including <code>.env</code> and user uploads. This cannot be undone.
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" data-close="modal-deploy">Cancel</button>
            <button class="btn btn-primary" id="btn-confirm-deploy">Deploy Now</button>
        </div>
    </div>
</div>

<!-- ======================================================================= -->
<!-- LOGS MODAL                                                               -->
<!-- ======================================================================= -->
<div class="modal-overlay" id="modal-logs" hidden>
    <div class="modal modal-lg">
        <div class="modal-header">
            <h3 class="modal-title">Deployment Logs — <span id="logs-project-name"></span></h3>
            <button class="modal-close" data-close="modal-logs">✕</button>
        </div>
        <div class="modal-body">
            <div id="logs-container" class="log-viewer">
                <p class="log-loading">Loading logs…</p>
            </div>
        </div>
    </div>
</div>

<!-- ======================================================================= -->
<!-- BACKUPS MODAL                                                            -->
<!-- ======================================================================= -->
<div class="modal-overlay" id="modal-backups" hidden>
    <div class="modal modal-lg">
        <div class="modal-header">
            <h3 class="modal-title">Backups — <span id="backups-project-name"></span></h3>
            <button class="modal-close" data-close="modal-backups">✕</button>
        </div>
        <div class="modal-body">
            <div id="backups-container">
                <p class="log-loading">Loading backups…</p>
            </div>
        </div>
    </div>
</div>

<!-- ======================================================================= -->
<!-- PROJECT FORM MODAL                                                       -->
<!-- ======================================================================= -->
<div class="modal-overlay" id="modal-project-form" hidden>
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title" id="project-form-title">Add Project</h3>
            <button class="modal-close" data-close="modal-project-form">✕</button>
        </div>
        <div class="modal-body">
            <form id="project-form" enctype="multipart/form-data" novalidate>
                <input type="hidden" id="project-form-id" name="id" value="">
                <!-- keep_pat=1 tells the server to preserve the existing PAT if this field is left blank -->
                <input type="hidden" id="project-form-keep-pat" name="keep_pat" value="0">

                <div class="form-group">
                    <label for="p-name">Project Name</label>
                    <input type="text" id="p-name" name="name" class="form-control" placeholder="my-app" required>
                    <small class="form-hint">Lowercase letters, numbers, hyphens, underscores only.</small>
                </div>

                <div class="form-group">
                    <label for="p-source-type">Source Type</label>
                    <select id="p-source-type" name="source_type" class="form-control">
                        <option value="github">GitHub Repository</option>
                        <option value="manual">Manual Zip Upload</option>
                    </select>
                </div>

                <div id="manual-upload-group" class="form-group" hidden>
                    <label for="p-archive">Upload Archive (.zip)</label>
                    <input type="file" id="p-archive" name="archive" class="form-control" accept=".zip">
                    <small class="form-hint">Uploading a new zip will overwrite the previously saved archive. You still need to click Deploy applied changes.</small>
                </div>

                <div id="github-fields">
                    <div class="form-group">
                        <label for="p-repo-url">GitHub Repository URL</label>
                        <input type="url" id="p-repo-url" name="repo_url" class="form-control"
                            placeholder="https://github.com/user/repo" required>
                    </div>

                    <div class="form-group">
                        <label for="p-branch">Branch</label>
                        <input type="text" id="p-branch" name="branch" class="form-control" value="main" required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="p-target-path">Target Directory (absolute path)</label>
                    <input type="text" id="p-target-path" name="target_path" class="form-control"
                        placeholder="/home/user/public_html/my-app" required>
                </div>

                <div class="form-group">
                    <label for="p-safe-keep">Safe-Keep Paths <span class="form-hint-inline">(comma-separated)</span></label>
                    <input type="text" id="p-safe-keep" name="safe_keep" class="form-control"
                        placeholder=".env, uploads/, storage/">
                </div>

                <div id="github-pat-field" class="form-group">
                    <label for="p-github-pat">GitHub PAT <span class="form-hint-inline">(optional, for private repos)</span></label>
                    <input type="password" id="p-github-pat" name="github_pat" class="form-control"
                        placeholder="ghp_xxxxxxxxxxxxxxxx" autocomplete="off">
                </div>

                <div class="alert alert-danger" id="project-form-error" hidden></div>
            </form>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" data-close="modal-project-form">Cancel</button>
            <button class="btn btn-primary" id="btn-save-project">Save Project</button>
        </div>
    </div>
</div>

<!-- ======================================================================= -->
<!-- DEPLOY PROGRESS OVERLAY                                                  -->
<!-- ======================================================================= -->
<div class="modal-overlay" id="modal-deploying" data-static="true" hidden>
    <div class="modal modal-lg">
        <div class="modal-body text-center">
            <div class="spinner"></div>
            <h3 class="deploy-status-title" id="deploy-status-title">Deploying…</h3>
            <p class="deploy-status-sub" id="deploy-status-sub">Please wait while the pipeline runs.</p>
            
            <div id="deploy-console" class="console-view" hidden>
                <!-- Real-time logs will be streamed here -->
            </div>
        </div>
    </div>
</div>

<!-- ======================================================================= -->
<!-- WEB TERMINAL MODAL (Stateless Runner)                                    -->
<!-- ======================================================================= -->
<div class="modal-overlay" id="modal-web-terminal" data-static="true" hidden>
    <div class="modal">
        <div class="modal-header" style="background:#111; border-bottom:1px solid #333;">
            <h3 class="modal-title" style="color:#e5e7eb;font-family:monospace;">>_ <span id="terminal-project-name">Terminal</span></h3>
            <button class="modal-close" data-close="modal-web-terminal" style="color:#aaa;">✕</button>
        </div>
        <div class="modal-body">
            <div id="terminal-log" class="terminal-log">
                <!-- Executed outputs will land here -->
                <div style="color: #888;">System Ready. Target configured.</div>
            </div>
            <div class="terminal-input-row">
                <span class="terminal-prompt-icon">$</span>
                <input type="text" id="terminal-input" class="terminal-input" placeholder="Type a command (e.g., composer dump-autoload) and press Enter..." autocomplete="off" spellcheck="false" />
            </div>
        </div>
    </div>
</div>

<!-- Pass CSRF token to JS -->
<script>
    window.CSRF_TOKEN = <?= json_encode($csrf) ?>;
</script>
<script src="assets/js/app.js"></script>
<script src="assets/js/terminal.js"></script>
</body>
</html>
