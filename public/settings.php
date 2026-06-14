<?php
// =============================================================================
// General Settings — Installation-wide configuration viewer
// =============================================================================
require_once __DIR__ . '/../app/helpers.php';
app_boot();
Auth::require();

$strategy        = detect_deploy_strategy();
$configuredStrat = defined('DEPLOY_STRATEGY') ? DEPLOY_STRATEGY : 'auto';
$terminalEnabled = !defined('TERMINAL_ENABLED') || TERMINAL_ENABLED;
$deployerRoot    = defined('DEPLOYER_ROOT') ? DEPLOYER_ROOT : dirname(__DIR__);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings | <?= h(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/app.css">
    <style>
        .settings-grid   { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 20px; }
        .settings-card   { background: var(--color-surface); border: 1px solid var(--color-border); border-radius: 8px; padding: 24px; }
        .settings-section-title { font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: .06em; color: var(--color-text-muted); margin: 0 0 16px; }
        .settings-list   { display: grid; grid-template-columns: 160px 1fr; gap: 10px 16px; margin: 0; font-size: 13px; }
        .settings-list dt { color: var(--color-text-muted); align-self: center; }
        .settings-list dd { margin: 0; align-self: center; word-break: break-all; }
        .settings-list code { font-family: Consolas, monospace; font-size: 12px; background: rgba(255,255,255,.05); padding: 2px 6px; border-radius: 3px; }
        .badge { display: inline-block; font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 4px; }
        .badge-success { background: rgba(62,207,142,.15); color: #3ecf8e; }
        .badge-warning { background: rgba(234,179,8,.12); color: #eab308; }
        .badge-danger  { background: rgba(239,68,68,.12);  color: #ef4444; }
        .action-bar    { margin-top: 20px; display: flex; gap: 10px; flex-wrap: wrap; }
        .note          { font-size: 12px; color: var(--color-text-muted); margin-top: 14px; }
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
            <a href="index.php" class="btn btn-ghost btn-sm">← Dashboard</a>
            <a href="logout.php" class="btn btn-ghost btn-sm">Logout</a>
        </div>
    </div>
</header>

<main class="main-content">
    <div class="container">

        <div class="page-header">
            <div>
                <h1 class="page-title">General Settings</h1>
                <p class="page-subtitle">Read from <code>config.php</code> — edit that file to change values</p>
            </div>
        </div>

        <div class="settings-grid">

            <!-- Deployer -->
            <div class="settings-card">
                <p class="settings-section-title">Deployer</p>
                <dl class="settings-list">
                    <dt>Root Path</dt>
                    <dd><code><?= h($deployerRoot) ?></code></dd>
                    <dt>App Name</dt>
                    <dd><?= h(APP_NAME) ?></dd>
                    <dt>Dev Mode</dt>
                    <dd>
                        <?php if (DEV_MODE): ?>
                            <span class="badge badge-warning">ON — SSL verification disabled</span>
                        <?php else: ?>
                            <span class="badge badge-success">OFF (production)</span>
                        <?php endif; ?>
                    </dd>
                </dl>
            </div>

            <!-- Deployment Strategy -->
            <div class="settings-card">
                <p class="settings-section-title">Deployment Strategy</p>
                <dl class="settings-list">
                    <dt>Configured</dt>
                    <dd><code><?= h($configuredStrat) ?></code></dd>
                    <dt>Resolved</dt>
                    <dd>
                        <code><?= h($strategy) ?></code>
                        <?php if ($strategy === 'symlink'): ?>
                            <span class="badge badge-success" style="margin-left:6px;">symlinks OK</span>
                        <?php else: ?>
                            <span class="badge badge-warning" style="margin-left:6px;">copy-in-place</span>
                        <?php endif; ?>
                    </dd>
                    <dt>Max Backups</dt>
                    <dd><?= h(MAX_BACKUPS_PER_PROJECT) ?> per project</dd>
                    <dt>Download Timeout</dt>
                    <dd><?= h(DOWNLOAD_TIMEOUT) ?>s</dd>
                    <dt>Lock Timeout</dt>
                    <dd><?= h(LOCK_TIMEOUT) ?>s</dd>
                    <dt>Hook Timeout</dt>
                    <dd><?= defined('HOOK_TIMEOUT') ? h(HOOK_TIMEOUT) . 's' : '300s (default)' ?></dd>
                </dl>
            </div>

            <!-- Terminal -->
            <div class="settings-card">
                <p class="settings-section-title">Terminal</p>
                <dl class="settings-list">
                    <dt>Site-wide</dt>
                    <dd>
                        <?php if ($terminalEnabled): ?>
                            <span class="badge badge-success">Enabled</span>
                        <?php else: ?>
                            <span class="badge badge-danger">Disabled (TERMINAL_ENABLED = false)</span>
                        <?php endif; ?>
                    </dd>
                    <dt>Timeout</dt>
                    <dd><?= defined('TERMINAL_TIMEOUT') ? h(TERMINAL_TIMEOUT) . 's' : '600s (default)' ?></dd>
                </dl>
                <p class="note">Per-project terminal access can be toggled in each project's edit form.</p>
            </div>

            <!-- Database -->
            <div class="settings-card">
                <p class="settings-section-title">Database</p>
                <dl class="settings-list">
                    <dt>Driver</dt>
                    <dd><code><?= h(DB_DRIVER) ?></code></dd>
                    <?php if (DB_DRIVER === 'sqlite'): ?>
                        <dt>File</dt>
                        <dd><code><?= h(defined('DB_PATH') ? DB_PATH : '—') ?></code></dd>
                    <?php else: ?>
                        <dt>Host</dt>
                        <dd><code><?= h(DB_HOST) ?>:<?= h(DB_PORT) ?></code></dd>
                        <dt>Database</dt>
                        <dd><code><?= h(DB_NAME) ?></code></dd>
                    <?php endif; ?>
                </dl>
            </div>

        </div><!-- /settings-grid -->

        <div class="settings-card" style="margin-top:20px;">
            <p class="settings-section-title">Actions</p>
            <p class="note" style="margin-top:0; margin-bottom:14px;">
                Run migrations after updating the deployer to apply any schema changes.
            </p>
            <div class="action-bar">
                <a href="migrate.php" class="btn btn-ghost btn-sm">Run Migrations</a>
                <a href="update.php"  class="btn btn-ghost btn-sm">Self-Update</a>
            </div>
        </div>

    </div>
</main>

</body>
</html>
