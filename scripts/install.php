<?php
/**
 * TeraPH Web Deployer — Bootstrap Installer
 *
 * Drop this single file into any web-accessible directory on your server
 * and open it in a browser. It downloads the latest TeraPH Web Deployer
 * from GitHub and configures it ready to use.
 *
 * Steps:
 *   1. Upload install.php to your server (e.g. public_html/install.php)
 *   2. Visit https://yourdomain.com/install.php in a browser
 *   3. Fill in the form and click "Download & Install"
 *   4. DELETE this file when finished — it contains no auth protection
 */

// =============================================================================
// Constants
// =============================================================================
const INST_ZIP_URL = 'https://github.com/tumbujohn/TeraPH-Web-Deployer/archive/refs/heads/main.zip';
const INST_SLUG    = 'TeraPH-Web-Deployer-main';   // wrapper folder GitHub puts in the zip
const INST_MIN_PHP = '8.1.0';
const INST_VERSION = '1.0';

// =============================================================================
// Output helpers
// =============================================================================
function h(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// =============================================================================
// Prerequisite checks (run on every request)
// =============================================================================
$checks = [
    [
        'label' => 'PHP ' . INST_MIN_PHP . '+',
        'ok'    => version_compare(PHP_VERSION, INST_MIN_PHP, '>='),
        'pass'  => 'PHP ' . PHP_VERSION,
        'fail'  => 'PHP ' . INST_MIN_PHP . '+ required — server has PHP ' . PHP_VERSION,
    ],
    [
        'label' => 'cURL extension',
        'ok'    => function_exists('curl_init'),
        'pass'  => 'Available',
        'fail'  => 'Missing — enable ext-curl in php.ini',
    ],
    [
        'label' => 'ZipArchive extension',
        'ok'    => class_exists('ZipArchive'),
        'pass'  => 'Available',
        'fail'  => 'Missing — enable ext-zip in php.ini',
    ],
    [
        'label' => 'Directory writable',
        'ok'    => is_writable(__DIR__),
        'pass'  => __DIR__,
        'fail'  => __DIR__ . ' is not writable — chmod 755',
    ],
];

$allPrereqOk = array_reduce($checks, fn($c, $r) => $c && $r['ok'], true);

// =============================================================================
// Default values (auto-detected)
// =============================================================================
$proto         = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host          = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scriptDirUrl  = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');
$defaultInstDir = __DIR__ . DIRECTORY_SEPARATOR . 'deployer';
$defaultUrl     = $proto . '://' . $host . $scriptDirUrl . '/deployer/public/';

// =============================================================================
// POST: Self-delete after successful install
// =============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['do_delete'])) {
    $dashUrl = $_POST['dash_url'] ?? $defaultUrl;
    @unlink(__FILE__);
    ?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Installer Removed</title>
    <style>
        body { background:#050505;color:#e5e7eb;font-family:system-ui,sans-serif;display:flex;
               align-items:center;justify-content:center;min-height:100vh;margin:0; }
        .box { background:#111;border:1px solid #222;border-radius:10px;padding:48px 40px;text-align:center;max-width:440px; }
        .icon { font-size:48px;margin-bottom:12px; }
        h2   { color:#fff;margin:0 0 12px; }
        a    { color:#3ecf8e;font-weight:600;text-decoration:none; }
        a:hover { text-decoration:underline; }
        p    { color:#9ca3af;font-size:14px;margin:0 0 20px; }
    </style>
</head>
<body>
<div class="box">
    <div class="icon">✔</div>
    <h2>Installer removed</h2>
    <p>Your deployer is live and this file no longer exists on the server.</p>
    <a href="<?= h($dashUrl) ?>">Open Dashboard →</a>
</div>
</body>
</html><?php
    exit;
}

// =============================================================================
// POST: Run installation
// =============================================================================
$installResult = null;
$installErrors = [];
$postData      = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['do_install'])) {
    $installDir = rtrim(trim($_POST['install_dir'] ?? $defaultInstDir), '/\\');
    $adminUser  = trim($_POST['admin_user'] ?? 'admin');
    $adminPass  = $_POST['admin_pass']  ?? '';
    $adminPass2 = $_POST['admin_pass2'] ?? '';
    $appUrl     = rtrim(trim($_POST['app_url'] ?? $defaultUrl), '/');

    $postData = compact('installDir', 'adminUser', 'appUrl');

    // ---- Validate ----
    if (empty($installDir))          $installErrors[] = 'Install directory is required.';
    if (empty($adminUser))           $installErrors[] = 'Admin username is required.';
    if (strlen($adminPass) < 8)      $installErrors[] = 'Password must be at least 8 characters.';
    if ($adminPass !== $adminPass2)  $installErrors[] = 'Passwords do not match.';
    if (!$allPrereqOk)               $installErrors[] = 'Fix the prerequisite failures above before installing.';

    // Ensure install dir is usable
    if (empty($installErrors)) {
        if (!is_dir($installDir)) {
            if (!@mkdir($installDir, 0755, true)) {
                $installErrors[] = "Cannot create install directory: {$installDir}";
            }
        } elseif (!is_writable($installDir)) {
            $installErrors[] = "Install directory is not writable: {$installDir}";
        }
    }

    if (empty($installErrors)) {
        $installResult = inst_run($installDir, $adminUser, $adminPass, $appUrl);
    }
}

// =============================================================================
// Install function
// =============================================================================
function inst_run(string $installDir, string $adminUser, string $adminPass, string $appUrl): array
{
    $log = [];
    $ok  = true;

    $tmpBase    = sys_get_temp_dir();
    $tmpDir     = $tmpBase . '/tera_install_' . time() . '_' . mt_rand(1000, 9999);
    $zipFile    = $tmpDir . '/deployer.zip';
    $extractDir = $tmpDir . '/extracted';

    set_time_limit(300);

    do {
        // Step 1 — temporary workspace
        $log[] = 'Creating temporary workspace…';
        if (!@mkdir($tmpDir, 0755, true) || !@mkdir($extractDir, 0755, true)) {
            $ok    = false;
            $log[] = "[ERROR] Cannot create temp directory: {$tmpDir}";
            break;
        }

        // Step 2 — download zip from GitHub
        $log[] = 'Downloading latest release from GitHub…';
        $ch = curl_init(INST_ZIP_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_USERAGENT      => 'TeraPH-Installer/' . INST_VERSION,
        ]);
        $zipData  = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($zipData === false || $curlErr) {
            $ok    = false;
            $log[] = "[ERROR] Download failed: {$curlErr}";
            break;
        }
        if ($httpCode !== 200) {
            $ok    = false;
            $log[] = "[ERROR] GitHub returned HTTP {$httpCode}. Check outbound internet access on this server.";
            break;
        }
        if (@file_put_contents($zipFile, $zipData) === false) {
            $ok    = false;
            $log[] = "[ERROR] Cannot write zip to temp directory: {$tmpDir}";
            break;
        }
        $log[] = 'Downloaded ' . number_format(strlen($zipData) / 1024 / 1024, 2) . ' MB.';
        unset($zipData); // free memory

        // Step 3 — extract archive
        $log[] = 'Extracting archive…';
        $zip = new ZipArchive();
        if ($zip->open($zipFile) !== true) {
            $ok    = false;
            $log[] = '[ERROR] Cannot open zip archive.';
            break;
        }
        $zip->extractTo($extractDir);
        $zip->close();
        @unlink($zipFile);

        $repoRoot = $extractDir . '/' . INST_SLUG;
        if (!is_dir($repoRoot)) {
            // GitHub sometimes changes the wrapper folder name — auto-detect
            $subdirs = glob($extractDir . '/*', GLOB_ONLYDIR) ?: [];
            if (empty($subdirs)) {
                $ok    = false;
                $log[] = '[ERROR] Archive has unexpected structure — no subdirectory found.';
                break;
            }
            $repoRoot = $subdirs[0];
            $log[] = 'Note: using extracted folder: ' . basename($repoRoot);
        }
        $log[] = 'Extracted OK.';

        // Step 4 — copy files into install directory (skip config.php — generated next)
        $log[] = "Copying files to: {$installDir}…";
        if (!inst_copy_dir($repoRoot, $installDir, ['config.php'])) {
            $ok    = false;
            $log[] = '[ERROR] File copy failed. Check directory permissions.';
            break;
        }
        $log[] = 'Files copied.';

        // Step 5 — generate config.php from the example
        $log[] = 'Generating config.php…';
        $examplePath = $repoRoot . '/config.php.example';
        if (!file_exists($examplePath)) {
            $ok    = false;
            $log[] = '[ERROR] config.php.example not found in the downloaded package.';
            break;
        }

        $cfg          = (string) file_get_contents($examplePath);
        $passwordHash = password_hash($adminPass, PASSWORD_BCRYPT, ['cost' => 12]);
        $deployerRoot = rtrim(str_replace(DIRECTORY_SEPARATOR, '/', $installDir), '/');

        // Patch string-value defines
        foreach ([
            'APP_USERNAME'      => $adminUser,
            'APP_PASSWORD_HASH' => $passwordHash,
            'APP_URL'           => $appUrl,
        ] as $name => $value) {
            $cfg = preg_replace(
                "/define\('" . preg_quote($name, '/') . "',\s*'[^']*'\)/",
                "define('" . $name . "', '" . addslashes($value) . "')",
                $cfg
            ) ?? $cfg;
        }

        // DEPLOYER_ROOT: the example value is dirname(__DIR__) — nested parens.
        // Match the entire line with the `m` flag so nested parens don't confuse [^)]+.
        $cfg = preg_replace(
            "/^define\('DEPLOYER_ROOT',.+$/m",
            "define('DEPLOYER_ROOT', '" . addslashes($deployerRoot) . "');",
            $cfg
        ) ?? $cfg;

        // Production defaults — also use full-line match to avoid nested-paren issues
        $cfg = preg_replace(
            "/^define\('DEV_MODE',.+$/m",
            "define('DEV_MODE', false);",
            $cfg
        ) ?? $cfg;
        $cfg = preg_replace(
            "/^define\('CURL_SSL_VERIFY',.+$/m",
            "define('CURL_SSL_VERIFY', true);",
            $cfg
        ) ?? $cfg;

        if (@file_put_contents($installDir . '/config.php', $cfg) === false) {
            $ok    = false;
            $log[] = '[ERROR] Cannot write config.php — check permissions on install directory.';
            break;
        }
        $log[] = 'config.php generated (DEV_MODE off, SSL verification on).';

        // Step 6 — create storage subdirectories
        $log[] = 'Creating storage directories…';
        foreach (['', '/logs', '/backups', '/tmp', '/archives'] as $sub) {
            $dir = $installDir . '/storage' . $sub;
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
        }
        // Block direct web access to storage/
        $htaccessPath = $installDir . '/storage/.htaccess';
        if (!file_exists($htaccessPath)) {
            @file_put_contents($htaccessPath, "Order deny,allow\nDeny from all\n");
        }
        $log[] = 'Storage directories created.';

        // Step 7 — clean up temp
        $log[] = 'Cleaning up temporary files…';
        inst_cleanup($tmpDir);

        $log[] = '✔ Installation complete!';

    } while (false);

    if (!$ok) {
        inst_cleanup($tmpDir);
    }

    return [
        'ok'        => $ok,
        'log'       => $log,
        'appUrl'    => $appUrl,
        'adminUser' => $adminUser,
    ];
}

/** Recursively copies $src into $dst, optionally skipping listed relative paths. */
function inst_copy_dir(string $src, string $dst, array $skip = []): bool
{
    if (!is_dir($dst) && !@mkdir($dst, 0755, true)) {
        return false;
    }

    $realSrc    = realpath($src) ?: $src;
    $srcRootLen = strlen(rtrim(str_replace(DIRECTORY_SEPARATOR, '/', $realSrc), '/')) + 1;

    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iter as $item) {
        $relPath = ltrim(str_replace(DIRECTORY_SEPARATOR, '/', substr($item->getRealPath(), $srcRootLen)), '/');
        if (in_array($relPath, $skip, true)) continue;

        $target = $dst . DIRECTORY_SEPARATOR . $relPath;

        if ($item->isDir()) {
            if (!is_dir($target)) @mkdir($target, 0755, true);
        } else {
            if (!@copy($item->getRealPath(), $target)) return false;
        }
    }

    return true;
}

/** Recursively deletes a directory. */
function inst_cleanup(string $dir): void
{
    if (!is_dir($dir)) return;
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iter as $item) {
        $item->isDir() && !$item->isLink()
            ? @rmdir($item->getRealPath())
            : @unlink($item->getRealPath());
    }
    @rmdir($dir);
}

// =============================================================================
// Render
// =============================================================================
$formInstDir = h($postData['installDir'] ?? $defaultInstDir);
$formUser    = h($postData['adminUser']  ?? 'admin');
$formUrl     = h($postData['appUrl']     ?? $defaultUrl);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TeraPH Web Deployer — Installer</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; }

        body   { background: #050505; color: #e5e7eb; font-family: 'Inter', system-ui, sans-serif;
                 margin: 0; padding: 24px 16px; min-height: 100vh; }
        .wrap  { max-width: 660px; margin: 0 auto; }

        .brand       { display: flex; align-items: center; gap: 10px; margin-bottom: 28px; }
        .brand-icon  { font-size: 28px; }
        .brand-name  { font-size: 20px; font-weight: 700; color: #fff; }
        .brand-ver   { font-size: 11px; color: #444; background: #1a1a1a; padding: 2px 8px;
                       border-radius: 10px; margin-left: 6px; }

        .card  { background: #111; border: 1px solid #1e1e1e; border-radius: 10px;
                 padding: 24px 28px; margin-bottom: 18px; }
        h2     { margin: 0 0 18px; font-size: 14px; font-weight: 600; color: #fff;
                 border-bottom: 1px solid #1e1e1e; padding-bottom: 12px;
                 text-transform: uppercase; letter-spacing: .06em; }

        /* Prereq rows */
        .chk-row   { display: flex; justify-content: space-between; align-items: center;
                     padding: 7px 0; border-bottom: 1px solid #161616; font-size: 13px; }
        .chk-row:last-child { border-bottom: none; }
        .chk-label { color: #9ca3af; }
        .chk-ok    { color: #3ecf8e; font-weight: 500; }
        .chk-err   { color: #f87171; font-weight: 500; }

        /* Form */
        .form-group   { margin-bottom: 16px; }
        label         { display: block; font-size: 11px; font-weight: 600; color: #6b7280;
                        margin-bottom: 5px; text-transform: uppercase; letter-spacing: .06em; }
        .form-control { width: 100%; background: #0a0a0a; border: 1px solid #252525;
                        border-radius: 6px; color: #e5e7eb; font-size: 14px;
                        padding: 9px 12px; outline: none; transition: border .15s;
                        font-family: inherit; }
        .form-control:focus { border-color: #3ecf8e; }
        .form-hint    { font-size: 11px; color: #4b5563; margin-top: 4px; }
        code          { font-family: Consolas, 'Courier New', monospace; background: #0a0a0a;
                        padding: 1px 5px; border-radius: 3px; font-size: 12px; color: #9ca3af; }

        /* Buttons */
        .btn         { display: inline-flex; align-items: center; gap: 6px;
                       padding: 10px 22px; border-radius: 6px; font-size: 14px;
                       font-weight: 600; cursor: pointer; border: none; transition: background .15s; }
        .btn-primary { background: #3ecf8e; color: #000; }
        .btn-primary:hover:not(:disabled) { background: #35b87e; }
        .btn-primary:disabled { background: #163d2a; color: #2d6649; cursor: not-allowed; }
        .btn-danger  { background: #1a0505; color: #fca5a5; border: 1px solid #7f1d1d;
                       margin-top: 10px; }
        .btn-danger:hover { background: #7f1d1d; color: #fff; }

        /* Alerts */
        .alert     { padding: 11px 16px; border-radius: 6px; font-size: 13px; margin-bottom: 16px; line-height: 1.6; }
        .alert-err { background: #130202; border: 1px solid #7f1d1d; color: #fca5a5; }
        .alert-warn { background: #100e00; border: 1px solid #3f3000; color: #d4b96a; }

        /* Install log */
        pre.inst-log { background: #000; border: 1px solid #1a1a1a; border-radius: 6px;
                       padding: 14px 16px; white-space: pre-wrap; word-break: break-word;
                       font-family: Consolas, 'Courier New', monospace; font-size: 12px;
                       color: #3ecf8e; max-height: 360px; overflow-y: auto; margin: 0; }
        pre.inst-log.failed { color: #f87171; }

        /* Success */
        .success-url { font-size: 17px; margin: 10px 0 16px; word-break: break-all; }
        .success-url a { color: #3ecf8e; font-weight: 600; }

        /* Explainer list */
        ol.steps { font-size: 13px; color: #9ca3af; line-height: 2; margin: 0; padding-left: 18px; }
        ol.steps code { color: #e5e7eb; }

        .footer { font-size: 11px; color: #333; text-align: center; margin-top: 32px; }
        .footer a { color: #555; }
    </style>
</head>
<body>
<div class="wrap">

    <div class="brand">
        <span class="brand-icon">⚡</span>
        <span class="brand-name">TeraPH Web Deployer</span>
        <span class="brand-ver">Installer v<?= INST_VERSION ?></span>
    </div>

<?php if ($installResult !== null): ?>
    <!-- ================================================================ -->
    <!-- Result Page                                                       -->
    <!-- ================================================================ -->
    <div class="card">
        <h2><?= $installResult['ok'] ? '✔ Installation Complete' : '✖ Installation Failed' ?></h2>
        <pre class="inst-log<?= $installResult['ok'] ? '' : ' failed' ?>"><?= h(implode("\n", $installResult['log'])) ?></pre>
    </div>

    <?php if ($installResult['ok']): ?>
    <div class="card">
        <h2>Your Deployer is Ready</h2>
        <p style="font-size:13px;color:#9ca3af;margin:0 0 8px;">Open your dashboard at:</p>
        <p class="success-url"><a href="<?= h($installResult['appUrl']) ?>"><?= h($installResult['appUrl']) ?></a></p>
        <p style="font-size:13px;color:#9ca3af;margin:0;">
            Log in with username <strong style="color:#e5e7eb"><?= h($installResult['adminUser']) ?></strong>
            and the password you set above. The first page load runs database migrations automatically.
        </p>
    </div>

    <div class="card">
        <h2>⚠ Security — Delete This Installer</h2>
        <p style="font-size:13px;color:#d4b96a;margin:0 0 14px;">
            This file has no password protection. Anyone who finds it could re-run the installer
            and overwrite your configuration. Delete it now.
        </p>
        <form method="post">
            <input type="hidden" name="do_delete" value="1">
            <input type="hidden" name="dash_url" value="<?= h($installResult['appUrl']) ?>">
            <button type="submit" class="btn btn-danger">🗑 Delete install.php from server</button>
        </form>
    </div>

    <?php else: ?>
    <div class="card">
        <p style="font-size:13px;color:#9ca3af;margin:0;">
            <a href="?" style="color:#3ecf8e;">← Try again</a>
        </p>
    </div>
    <?php endif; ?>

<?php else: ?>
    <!-- ================================================================ -->
    <!-- Setup Form                                                        -->
    <!-- ================================================================ -->

    <!-- Prerequisites -->
    <div class="card">
        <h2>Server Checks</h2>
        <?php foreach ($checks as $c): ?>
        <div class="chk-row">
            <span class="chk-label"><?= h($c['label']) ?></span>
            <span class="<?= $c['ok'] ? 'chk-ok' : 'chk-err' ?>">
                <?= $c['ok'] ? '✔ ' . h($c['pass']) : '✖ ' . h($c['fail']) ?>
            </span>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if (!empty($installErrors)): ?>
    <div class="alert alert-err">
        <?php foreach ($installErrors as $e): ?>
            <div><?= h($e) ?></div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="alert alert-warn">
        ⚠ <strong>Security notice:</strong> this file is publicly accessible with no authentication.
        Delete it immediately after installation.
    </div>

    <!-- Form -->
    <div class="card">
        <h2>Installation Settings</h2>
        <form method="post">
            <input type="hidden" name="do_install" value="1">

            <div class="form-group">
                <label for="install_dir">Install Directory <span style="font-weight:400;text-transform:none;letter-spacing:0">(absolute server path)</span></label>
                <input type="text" id="install_dir" name="install_dir" class="form-control"
                       value="<?= $formInstDir ?>" required>
                <p class="form-hint">
                    Where the deployer files will be extracted. Default is a <code>deployer/</code> folder
                    next to this file. The directory will be created if it does not exist.
                </p>
            </div>

            <div class="form-group">
                <label for="app_url">Dashboard URL</label>
                <input type="url" id="app_url" name="app_url" class="form-control"
                       value="<?= $formUrl ?>" required>
                <p class="form-hint">
                    Full public URL to the deployer's <code>public/</code> directory
                    (auto-detected above — correct it if your setup differs).
                </p>
            </div>

            <div class="form-group">
                <label for="admin_user">Admin Username</label>
                <input type="text" id="admin_user" name="admin_user" class="form-control"
                       value="<?= $formUser ?>" required autocomplete="off">
            </div>

            <div class="form-group">
                <label for="admin_pass">Admin Password <span style="font-weight:400;text-transform:none;letter-spacing:0">(min 8 characters)</span></label>
                <input type="password" id="admin_pass" name="admin_pass" class="form-control"
                       required autocomplete="new-password">
            </div>

            <div class="form-group">
                <label for="admin_pass2">Confirm Password</label>
                <input type="password" id="admin_pass2" name="admin_pass2" class="form-control"
                       required autocomplete="new-password">
            </div>

            <button type="submit" class="btn btn-primary" <?= !$allPrereqOk ? 'disabled' : '' ?>>
                ⚡ Download &amp; Install
            </button>
        </form>
    </div>

    <!-- What happens -->
    <div class="card">
        <h2>What Happens</h2>
        <ol class="steps">
            <li>Download the latest deployer zip from <code>github.com/tumbujohn/TeraPH-Web-Deployer</code></li>
            <li>Extract and copy all files into the chosen install directory</li>
            <li>Generate <code>config.php</code> with your credentials (<code>DEV_MODE</code> off, SSL on)</li>
            <li>Create <code>storage/</code> subdirectories and block direct web access to them</li>
            <li>Database migrations run automatically on your first login</li>
        </ol>
    </div>

<?php endif; ?>

    <p class="footer">
        TeraPH Web Deployer ·
        <a href="https://github.com/tumbujohn/TeraPH-Web-Deployer">github.com/tumbujohn/TeraPH-Web-Deployer</a>
    </p>

</div>
</body>
</html>
