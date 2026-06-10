<?php
// =============================================================================
// TeraPH Web Deployer — Self-Update
// Pulls the latest code from the public GitHub repo and applies it in-place.
// config.php and storage/ are never touched.
// =============================================================================
require_once __DIR__ . '/../app/helpers.php';
app_boot();
Auth::require();

// ---- Config -----------------------------------------------------------------

const UPDATE_ZIP_URL = 'https://github.com/tumbujohn/TeraPH-Web-Deployer/archive/refs/heads/main.zip';
const UPDATE_SLUG    = 'TeraPH-Web-Deployer-main'; // wrapper folder GitHub puts inside the zip

$baseDir  = dirname(__DIR__);
$preserve = ['config.php', 'storage'];   // never deleted, never overwritten

// ---- GET: Confirmation page -------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Self-Update | TeraPH Deployer</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/app.css">
    <style>
        body  { padding: 40px; background: #000; color: #e5e7eb; }
        .box  { background: #111; padding: 30px; border-radius: 8px; border: 1px solid #333; max-width: 680px; margin: 0 auto; }
        h1    { margin-top: 0; color: #fff; font-size: 22px; border-bottom: 1px solid #333; padding-bottom: 14px; }
        .note { background: #1a1a00; border: 1px solid #554400; border-radius: 6px; padding: 14px 18px; margin: 18px 0; font-size: 13px; color: #d4b96a; line-height: 1.6; }
        .note strong { color: #f0d080; }
        .keep { background: #0d1a0d; border: 1px solid #1e4d1e; border-radius: 6px; padding: 12px 18px; margin: 18px 0; font-size: 13px; color: #6dbf6d; line-height: 1.7; }
        .keep code { font-family: Consolas, monospace; background: #0a120a; padding: 1px 5px; border-radius: 3px; }
        .btn  { background: #3ecf8e; color: #000; border: none; padding: 10px 28px; border-radius: 5px; font-size: 14px; font-weight: 600; cursor: pointer; }
        .btn:hover { background: #35b87e; }
        .back { color: #3ecf8e; text-decoration: none; border: 1px solid #3ecf8e; padding: 8px 16px; border-radius: 4px; font-size: 14px; }
        .back:hover { background: rgba(62,207,142,.1); }
        .actions { margin-top: 24px; display: flex; gap: 14px; align-items: center; }
    </style>
</head>
<body>
<div class="box">
    <h1>Self-Update</h1>

    <p>This will download the latest code from the <strong>main</strong> branch of the public GitHub repository and apply it to this installation.</p>

    <div class="note">
        <strong>⚠ A backup is created first.</strong><br>
        The current installation (excluding <code>storage/</code>) is zipped to
        <code>storage/backups/</code> before any files are changed.
        If something goes wrong you can restore from that zip manually.
    </div>

    <div class="keep">
        <strong>The following paths will never be touched:</strong><br>
        <?php foreach ($preserve as $p): ?>
            <code><?= h($p) ?></code><br>
        <?php endforeach; ?>
    </div>

    <p style="font-size:13px; color:#9ca3af;">
        After the update completes, run <a href="migrate.php" style="color:#3ecf8e;">database migrations</a>
        if the schema has changed.
    </p>

    <div class="actions">
        <form method="post">
            <button class="btn" type="submit">Download &amp; Apply Update</button>
        </form>
        <a href="index.php" class="back">← Cancel</a>
    </div>
</div>
</body>
</html>
<?php
    exit;
}

// ---- POST: Run the update ---------------------------------------------------

set_time_limit(300);

$log    = [];
$ok     = true;
$tmpDir = sys_get_temp_dir() . '/tera_update_' . time();
$zipFile    = $tmpDir . '/update.zip';
$extractDir = $tmpDir . '/extracted';

$uplog = function(string $msg) use (&$log): void {
    $log[] = $msg;
};

$upAbort = function(string $msg) use (&$log, &$ok, $tmpDir): void {
    $ok = false;
    $log[] = '[ABORTED] ' . $msg;
    up_cleanup($tmpDir);
};

function up_cleanup(string $dir): void
{
    if (!is_dir($dir)) return;
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iter as $item) {
        $item->isDir() && !$item->isLink() ? @rmdir($item->getRealPath()) : @unlink($item->getRealPath());
    }
    @rmdir($dir);
}

do { // single-pass do-while so we can 'break' on error without goto

    // Step 1 — temp workspace
    $uplog("Creating temp workspace...");
    if (!mkdir($tmpDir, 0755, true) || !mkdir($extractDir, 0755, true)) {
        $upAbort("Cannot create temp directory: {$tmpDir}");
        break;
    }
    $uplog("Temp dir ready.");

    // Step 2 — download
    $uplog("Downloading latest release from GitHub...");
    $sslVerify = defined('CURL_SSL_VERIFY') ? CURL_SSL_VERIFY : true;
    $timeout   = defined('DOWNLOAD_TIMEOUT') ? (int) DOWNLOAD_TIMEOUT : 120;

    $ch = curl_init(UPDATE_ZIP_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => $sslVerify,
        CURLOPT_SSL_VERIFYHOST => $sslVerify ? 2 : 0,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_USERAGENT      => 'TeraPH-Self-Updater/1.0',
    ]);
    $zipData  = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($zipData === false || $curlErr) {
        $upAbort("Download failed: {$curlErr}");
        break;
    }
    if ($httpCode !== 200) {
        $upAbort("HTTP {$httpCode} received from GitHub.");
        break;
    }
    if (file_put_contents($zipFile, $zipData) === false) {
        $upAbort("Cannot write zip to temp directory.");
        break;
    }
    $sizeMB = number_format(strlen($zipData) / 1024 / 1024, 2);
    $uplog("Downloaded {$sizeMB} MB.");

    // Step 3 — extract
    $uplog("Extracting archive...");
    $zip = new ZipArchive();
    if ($zip->open($zipFile) !== true) {
        $upAbort("Cannot open zip archive.");
        break;
    }
    $zip->extractTo($extractDir);
    $zip->close();

    $repoRoot = $extractDir . '/' . UPDATE_SLUG;
    if (!is_dir($repoRoot)) {
        $subdirs = glob($extractDir . '/*', GLOB_ONLYDIR);
        if (empty($subdirs)) {
            $upAbort("Extracted archive is empty or has unexpected structure.");
            break;
        }
        $repoRoot = $subdirs[0];
        $uplog("Note: using extracted folder: " . basename($repoRoot));
    }
    $uplog("Extracted OK.");

    // Step 4 — backup
    $uplog("Creating backup of current installation...");
    @mkdir($baseDir . '/storage/backups', 0755, true);
    $backupFile = $baseDir . '/storage/backups/pre_update_' . date('Ymd_His') . '.zip';
    $bzip = new ZipArchive();
    if ($bzip->open($backupFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
        $bIter   = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($baseDir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        $rootLen = strlen(rtrim(str_replace(DIRECTORY_SEPARATOR, '/', realpath($baseDir)), '/')) + 1;
        foreach ($bIter as $bItem) {
            if (!$bItem->isFile()) continue;
            $rel = str_replace(DIRECTORY_SEPARATOR, '/', substr($bItem->getRealPath(), $rootLen));
            if (str_starts_with($rel, 'storage/')) continue; // skip potentially huge storage tree
            $bzip->addFile($bItem->getRealPath(), $rel);
        }
        $bzip->close();
        $bSizeMB = number_format(filesize($backupFile) / 1024 / 1024, 2);
        $uplog("Backup saved: " . basename($backupFile) . " ({$bSizeMB} MB)");
    } else {
        $uplog("Warning: could not create backup zip — continuing anyway.");
    }

    // Step 5 — apply (skip preserved paths in both clear and copy)
    $uplog("Clearing old files (preserving: " . implode(', ', $preserve) . ")...");
    $fm = new FileManager();
    $fm->clear($baseDir, $preserve);

    $uplog("Copying new files...");
    $fm->copyContents($repoRoot, $baseDir, $preserve);
    $uplog("Files updated.");

    // Step 6 — cleanup
    $uplog("Cleaning up temp files...");
    up_cleanup($tmpDir);
    $uplog("Done.");

} while (false);

// ---- Render result ----------------------------------------------------------
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Self-Update | TeraPH Deployer</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/app.css">
    <style>
        body  { padding: 40px; background: #000; color: #e5e7eb; }
        .box  { background: #111; padding: 30px; border-radius: 8px; border: 1px solid #333; max-width: 800px; margin: 0 auto; }
        h1    { margin-top: 0; color: #fff; font-size: 22px; border-bottom: 1px solid #333; padding-bottom: 14px; }
        pre   { background: #000; padding: 15px; border-radius: 4px; border: 1px solid #222; white-space: pre-wrap; font-family: Consolas, monospace; font-size: 13px; color: #3ecf8e; }
        pre.err { color: #f87171; }
        .back { margin-top: 20px; display: inline-block; color: #3ecf8e; text-decoration: none; border: 1px solid #3ecf8e; padding: 8px 16px; border-radius: 4px; }
        .back:hover { background: rgba(62,207,142,.1); }
        .mig  { margin-top: 16px; display: inline-block; color: #d4b96a; text-decoration: none; border: 1px solid #554400; padding: 8px 16px; border-radius: 4px; }
        .mig:hover { background: rgba(212,185,106,.1); }
    </style>
</head>
<body>
<div class="box">
    <h1>Self-Update <?= $ok ? '— Complete' : '— Failed' ?></h1>

    <pre class="<?= $ok ? '' : 'err' ?>"><?= h(implode("\n", $log)) ?></pre>

    <?php if ($ok): ?>
    <p style="font-size:13px; color:#9ca3af;">
        If the schema changed in this release, run migrations now.
    </p>
    <a href="migrate.php" class="mig">Run Migrations →</a>
    <?php endif; ?>

    <br>
    <a href="index.php" class="back">← Return to Dashboard</a>
</div>
</body>
</html>
