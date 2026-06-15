<?php
// =============================================================================
// General Settings — Installation-wide configuration viewer
// =============================================================================
require_once __DIR__ . '/../app/helpers.php';
app_boot();
Auth::require();

// ---- Deploy strategy -------------------------------------------------------
$strategy        = detect_deploy_strategy();
$configuredStrat = defined('DEPLOY_STRATEGY') ? DEPLOY_STRATEGY : 'auto';
$terminalEnabled = !defined('TERMINAL_ENABLED') || TERMINAL_ENABLED;
$deployerRoot    = defined('DEPLOYER_ROOT') ? DEPLOYER_ROOT : dirname(__DIR__);

// ---- PHP environment -------------------------------------------------------
$phpVersionOk = version_compare(PHP_VERSION, '8.1.0', '>=');
$dbExt        = DB_DRIVER === 'mysql' ? 'pdo_mysql' : 'pdo_sqlite';
$extChecks    = ['zip', 'curl', 'pdo', $dbExt];

// ---- Storage dirs ----------------------------------------------------------
$storageRoot = defined('STORAGE_PATH') ? STORAGE_PATH : dirname(__DIR__) . '/storage';

function settings_dir_info(string $path): array
{
    $exists   = is_dir($path);
    $writable = $exists && is_writable($path);
    $size     = 0;
    $count    = 0;
    if ($exists) {
        try {
            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($iter as $f) {
                if ($f->isFile()) { $size += $f->getSize(); $count++; }
            }
        } catch (Exception) {}
    }
    return compact('exists', 'writable', 'size', 'count');
}

$storageDirs = [
    'Backups'  => defined('BACKUP_PATH') ? BACKUP_PATH : $storageRoot . '/backups',
    'Tmp'      => defined('TMP_PATH')    ? TMP_PATH    : $storageRoot . '/tmp',
    'Logs'     => defined('LOG_PATH')    ? LOG_PATH    : $storageRoot . '/logs',
    'Archives' => $storageRoot . '/archives',
];

// ---- Backup summary --------------------------------------------------------
$backupPath  = $storageDirs['Backups'];
$backupZips  = [];
if (is_dir($backupPath)) {
    try {
        $bIter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($backupPath, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($bIter as $f) {
            if ($f->isFile() && strtolower($f->getExtension()) === 'zip') {
                $backupZips[] = $f->getSize();
            }
        }
    } catch (Exception) {}
}
$totalBackups    = count($backupZips);
$totalBackupSize = (int) array_sum($backupZips);

// ---- Integrations ----------------------------------------------------------
$hasGlobalPat     = defined('GITHUB_PAT')     && trim(GITHUB_PAT) !== '';
$hasWebhookSecret = defined('WEBHOOK_SECRET') && trim(WEBHOOK_SECRET) !== '';
$hasSecretKey     = defined('SECRET_KEY')     && SECRET_KEY !== '';

$csrfToken = csrf_token();
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
        .settings-grid        { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 20px; }
        .settings-card        { background: var(--color-surface); border: 1px solid var(--color-border); border-radius: 8px; padding: 24px; }
        .settings-card--wide  { grid-column: 1 / -1; }
        .settings-section-title { font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: .06em; color: var(--color-text-muted); margin: 0 0 16px; }
        .settings-list        { display: grid; grid-template-columns: 160px 1fr; gap: 10px 16px; margin: 0; font-size: 13px; }
        .settings-list dt     { color: var(--color-text-muted); align-self: center; }
        .settings-list dd     { margin: 0; align-self: center; word-break: break-all; }
        .settings-list code   { font-family: Consolas, monospace; font-size: 12px; background: rgba(255,255,255,.05); padding: 2px 6px; border-radius: 3px; }
        .badge                { display: inline-block; font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 4px; }
        .badge-success        { background: rgba(62,207,142,.15); color: #3ecf8e; }
        .badge-warning        { background: rgba(234,179,8,.12); color: #eab308; }
        .badge-danger         { background: rgba(239,68,68,.12);  color: #ef4444; }
        .badge-neutral        { background: rgba(255,255,255,.07); color: var(--color-text-muted); }
        .action-bar           { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
        .action-result        { font-size: 12px; color: var(--color-text-muted); margin-top: 10px; min-height: 18px; }
        .action-result.ok     { color: #3ecf8e; }
        .action-result.err    { color: #ef4444; }
        .note                 { font-size: 12px; color: var(--color-text-muted); margin-top: 14px; }

        /* Storage table */
        .storage-table        { width: 100%; border-collapse: collapse; font-size: 13px; }
        .storage-table th     { text-align: left; font-weight: 500; color: var(--color-text-muted); padding-bottom: 10px; font-size: 12px; }
        .storage-table td     { padding: 7px 0; vertical-align: middle; border-top: 1px solid rgba(255,255,255,.05); }
        .storage-table td:first-child { width: 90px; }
        .storage-table td:last-child  { text-align: right; color: var(--color-text-muted); font-size: 12px; white-space: nowrap; }
        .storage-path         { font-family: Consolas, monospace; font-size: 11px; color: var(--color-text-muted); display: block; margin-top: 2px; word-break: break-all; }

        /* Ext check list */
        .ext-list             { display: flex; flex-direction: column; gap: 8px; }
        .ext-row              { display: flex; align-items: center; justify-content: space-between; font-size: 13px; }
        .ext-name             { font-family: Consolas, monospace; font-size: 12px; }
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

            <!-- PHP Environment -->
            <div class="settings-card">
                <p class="settings-section-title">PHP Environment</p>
                <dl class="settings-list" style="margin-bottom:16px;">
                    <dt>PHP Version</dt>
                    <dd>
                        <?= h(PHP_VERSION) ?>
                        <?php if ($phpVersionOk): ?>
                            <span class="badge badge-success" style="margin-left:6px;">OK</span>
                        <?php else: ?>
                            <span class="badge badge-danger" style="margin-left:6px;">Requires 8.1+</span>
                        <?php endif; ?>
                    </dd>
                </dl>
                <div class="ext-list">
                    <?php foreach ($extChecks as $ext): ?>
                        <?php $loaded = extension_loaded($ext); ?>
                        <div class="ext-row">
                            <span class="ext-name"><?= h($ext) ?></span>
                            <?php if ($loaded): ?>
                                <span class="badge badge-success">loaded</span>
                            <?php else: ?>
                                <span class="badge badge-danger">missing</span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Session -->
            <div class="settings-card">
                <p class="settings-section-title">Session</p>
                <dl class="settings-list">
                    <dt>Cookie Name</dt>
                    <dd><code><?= h(defined('SESSION_NAME') ? SESSION_NAME : 'tera_deployer_session') ?></code></dd>
                    <dt>Idle Timeout</dt>
                    <dd>
                        <?php
                        $sTimeout = defined('SESSION_TIMEOUT') ? (int) SESSION_TIMEOUT : 3600;
                        $mins     = (int) ($sTimeout / 60);
                        echo h($sTimeout) . 's';
                        if ($mins > 0) echo ' <span style="color:var(--color-text-muted)">(' . $mins . ' min)</span>';
                        ?>
                    </dd>
                    <dt>Last Activity</dt>
                    <dd>
                        <?php
                        $lastActive = $_SESSION['last_activity'] ?? null;
                        echo $lastActive ? h(time_ago(date('Y-m-d H:i:s', $lastActive))) : '<span style="color:var(--color-text-muted)">—</span>';
                        ?>
                    </dd>
                </dl>
            </div>

            <!-- Integrations -->
            <div class="settings-card">
                <p class="settings-section-title">Integrations</p>
                <dl class="settings-list">
                    <dt>Secret Key</dt>
                    <dd>
                        <?php if ($hasSecretKey): ?>
                            <span class="badge badge-success">Set</span>
                        <?php else: ?>
                            <span class="badge badge-danger">Not set</span>
                            <span style="font-size:11px; color:#ef4444; margin-left:6px;">env vars at risk if password changes</span>
                        <?php endif; ?>
                    </dd>
                    <dt>Global PAT</dt>
                    <dd>
                        <?php if ($hasGlobalPat): ?>
                            <span class="badge badge-success">Set</span>
                            <span style="font-size:12px; color:var(--color-text-muted); margin-left:6px;">
                                <?= h(substr(GITHUB_PAT, 0, 8)) ?>…
                            </span>
                        <?php else: ?>
                            <span class="badge badge-neutral">Not set</span>
                            <span style="font-size:12px; color:var(--color-text-muted); margin-left:6px;">per-project PAT required</span>
                        <?php endif; ?>
                    </dd>
                    <dt>Webhook Secret</dt>
                    <dd>
                        <?php if ($hasWebhookSecret): ?>
                            <span class="badge badge-success">Set</span>
                        <?php else: ?>
                            <span class="badge badge-neutral">Not set</span>
                            <span style="font-size:12px; color:var(--color-text-muted); margin-left:6px;">webhooks disabled</span>
                        <?php endif; ?>
                    </dd>
                    <dt>App URL</dt>
                    <dd>
                        <?php $appUrl = defined('APP_URL') ? trim(APP_URL) : ''; ?>
                        <?php if ($appUrl): ?>
                            <code><?= h($appUrl) ?></code>
                        <?php else: ?>
                            <span class="badge badge-warning">Not set</span>
                        <?php endif; ?>
                    </dd>
                </dl>
            </div>

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
                            <span class="badge badge-warning">ON — SSL disabled</span>
                        <?php else: ?>
                            <span class="badge badge-success">OFF (production)</span>
                        <?php endif; ?>
                    </dd>
                    <dt>SSL Verify</dt>
                    <dd>
                        <?php if (defined('CURL_SSL_VERIFY') && CURL_SSL_VERIFY): ?>
                            <span class="badge badge-success">Enabled</span>
                        <?php else: ?>
                            <span class="badge badge-warning">Disabled</span>
                        <?php endif; ?>
                    </dd>
                </dl>
            </div>

            <!-- Deployment -->
            <div class="settings-card">
                <p class="settings-section-title">Deployment</p>
                <dl class="settings-list">
                    <dt>Strategy</dt>
                    <dd>
                        <code><?= h($configuredStrat) ?></code>
                        <?php if ($configuredStrat === 'auto'): ?>
                            → <code><?= h($strategy) ?></code>
                            <?php if ($strategy === 'symlink'): ?>
                                <span class="badge badge-success" style="margin-left:4px;">symlinks OK</span>
                            <?php else: ?>
                                <span class="badge badge-warning" style="margin-left:4px;">copy mode</span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </dd>
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
                            <span class="badge badge-danger">Disabled</span>
                        <?php endif; ?>
                    </dd>
                    <dt>Command Timeout</dt>
                    <dd><?= defined('TERMINAL_TIMEOUT') ? h(TERMINAL_TIMEOUT) . 's' : '600s (default)' ?></dd>
                </dl>
                <p class="note">Per-project terminal access is toggled in each project's edit form.</p>
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
                        <dt>File Size</dt>
                        <dd>
                            <?php
                            $dbFile = defined('DB_PATH') ? DB_PATH : '';
                            echo ($dbFile && file_exists($dbFile))
                                ? h(format_bytes((int) filesize($dbFile)))
                                : '<span style="color:var(--color-text-muted)">—</span>';
                            ?>
                        </dd>
                    <?php else: ?>
                        <dt>Host</dt>
                        <dd><code><?= h(DB_HOST) ?>:<?= h(DB_PORT) ?></code></dd>
                        <dt>Database</dt>
                        <dd><code><?= h(DB_NAME) ?></code></dd>
                        <dt>User</dt>
                        <dd><code><?= h(DB_USER) ?></code></dd>
                    <?php endif; ?>
                </dl>
            </div>

            <!-- Backups -->
            <div class="settings-card">
                <p class="settings-section-title">Backups</p>
                <dl class="settings-list">
                    <dt>Total Backups</dt>
                    <dd>
                        <?= h($totalBackups) ?> zip<?= $totalBackups !== 1 ? 's' : '' ?>
                        <?php if ($totalBackupSize > 0): ?>
                            <span style="color:var(--color-text-muted); font-size:12px; margin-left:4px;">
                                · <?= h(format_bytes($totalBackupSize)) ?>
                            </span>
                        <?php endif; ?>
                    </dd>
                    <dt>Limit</dt>
                    <dd><?= h(MAX_BACKUPS_PER_PROJECT) ?> per project</dd>
                    <dt>Path</dt>
                    <dd><code><?= h($storageDirs['Backups']) ?></code></dd>
                </dl>
            </div>

        </div><!-- /settings-grid -->

        <!-- Storage Health (full width) -->
        <div class="settings-card settings-card--wide" style="margin-top:20px;">
            <p class="settings-section-title">Storage Health</p>
            <table class="storage-table">
                <thead>
                    <tr>
                        <th>Directory</th>
                        <th>Path</th>
                        <th>Status</th>
                        <th style="text-align:right;">Files · Size</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($storageDirs as $label => $path): ?>
                        <?php $info = settings_dir_info($path); ?>
                        <tr>
                            <td><strong><?= h($label) ?></strong></td>
                            <td><span class="storage-path"><?= h($path) ?></span></td>
                            <td>
                                <?php if (!$info['exists']): ?>
                                    <span class="badge badge-danger">Missing</span>
                                <?php elseif (!$info['writable']): ?>
                                    <span class="badge badge-warning">Read-only</span>
                                <?php else: ?>
                                    <span class="badge badge-success">OK</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($info['exists']): ?>
                                    <?= h($info['count']) ?> files · <?= h(format_bytes($info['size'])) ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Actions (full width) -->
        <div class="settings-card settings-card--wide" style="margin-top:20px;">
            <p class="settings-section-title">Actions</p>
            <p class="note" style="margin-top:0; margin-bottom:14px;">
                Maintenance operations — run these from the browser when CLI access is unavailable.
            </p>
            <div class="action-bar">
                <a href="migrate.php" class="btn btn-ghost btn-sm">Run Migrations</a>
                <a href="update.php"  class="btn btn-ghost btn-sm">Self-Update</a>
                <button class="btn btn-ghost btn-sm" id="btn-clear-tmp">Clear Tmp</button>
                <button class="btn btn-ghost btn-sm" id="btn-reconcile">Reconcile Stuck Deploys</button>
            </div>
            <p class="action-result" id="action-result"></p>
        </div>

    </div>
</main>

<script>
(function () {
    const csrfToken = <?= json_encode($csrfToken) ?>;
    const resultEl  = document.getElementById('action-result');

    async function runAction(btn, action) {
        btn.disabled = true;
        resultEl.textContent = 'Running…';
        resultEl.className   = 'action-result';

        try {
            const fd = new FormData();
            fd.append('action', action);
            fd.append('_csrf',  csrfToken);

            const res  = await fetch('settings_action.php', { method: 'POST', body: fd });
            const json = await res.json();

            resultEl.textContent = json.message || (json.success ? 'Done.' : 'Error.');
            resultEl.className   = 'action-result ' + (json.success ? 'ok' : 'err');

            if (json.success && json.data?.details?.length) {
                resultEl.textContent += ' — ' + json.data.details.join(', ');
            }
        } catch (e) {
            resultEl.textContent = 'Network error: ' + e.message;
            resultEl.className   = 'action-result err';
        } finally {
            btn.disabled = false;
        }
    }

    document.getElementById('btn-clear-tmp').addEventListener('click', function () {
        if (confirm('Clear all files from the tmp directory?')) runAction(this, 'clear_tmp');
    });

    document.getElementById('btn-reconcile').addEventListener('click', function () {
        runAction(this, 'deploy_reconcile');
    });
})();
</script>

</body>
</html>
