<?php
// =============================================================================
// Composer Download Endpoint
// Downloads composer-stable.phar from getcomposer.org into a project's
// target directory, verified against the published SHA256 checksum.
// =============================================================================
require_once __DIR__ . '/../app/helpers.php';
app_boot();
Auth::require();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit("Method not allowed.");
}

csrf_verify();

if (!defined('TERMINAL_ENABLED') || !TERMINAL_ENABLED) {
    http_response_code(403);
    exit("Terminal is disabled on this installation.");
}

$projectId = (int) ($_POST['project_id'] ?? 0);
$project   = Project::find($projectId);

if (!$project) {
    http_response_code(404);
    exit("Project not found.");
}

if (isset($project['terminal_enabled']) && !$project['terminal_enabled']) {
    http_response_code(403);
    exit("Terminal is disabled for this project.");
}

$targetDir = $project['target_path'];
if (!is_dir($targetDir)) {
    http_response_code(422);
    exit("Target directory does not exist: " . basename($targetDir));
}

$pharPath  = $targetDir . '/composer.phar';
$pharUrl   = 'https://getcomposer.org/composer-stable.phar';
$sha256Url = 'https://getcomposer.org/download/latest-stable/composer.phar.sha256sum';
$sslVerify = defined('CURL_SSL_VERIFY') ? CURL_SSL_VERIFY : true;

// Step 1 — fetch the expected checksum
$ch = curl_init($sha256Url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_SSL_VERIFYPEER => $sslVerify,
    CURLOPT_SSL_VERIFYHOST => $sslVerify ? 2 : 0,
    CURLOPT_USERAGENT      => 'TeraPH-Deployer/1.0',
    CURLOPT_FOLLOWLOCATION => true,
]);
$sha256Raw    = curl_exec($ch);
$sha256Status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$expectedHash = null;
if ($sha256Raw && $sha256Status === 200) {
    $expectedHash = trim(explode(' ', trim($sha256Raw))[0]);
}

// Step 2 — download the phar to a temp file
$tmpPath = TMP_PATH . '/composer_dl_' . time() . '.phar';
$fh      = fopen($tmpPath, 'wb');
if (!$fh) {
    exit("Failed to open temp file for writing.");
}

$ch = curl_init($pharUrl);
curl_setopt_array($ch, [
    CURLOPT_FILE           => $fh,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT        => 120,
    CURLOPT_SSL_VERIFYPEER => $sslVerify,
    CURLOPT_SSL_VERIFYHOST => $sslVerify ? 2 : 0,
    CURLOPT_USERAGENT      => 'TeraPH-Deployer/1.0',
]);
$ok       = curl_exec($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
fclose($fh);

if (!$ok || $httpCode !== 200) {
    @unlink($tmpPath);
    http_response_code(500);
    exit("Download failed (HTTP {$httpCode}).");
}

// Step 3 — verify checksum
if ($expectedHash !== null) {
    $actualHash = hash_file('sha256', $tmpPath);
    if ($actualHash !== $expectedHash) {
        @unlink($tmpPath);
        http_response_code(500);
        exit("SHA256 verification failed — aborting for security. Re-try or check your connection.");
    }
    $verifiedNote = ' (SHA256 verified)';
} else {
    $verifiedNote = ' (checksum unavailable — downloaded without verification)';
}

// Step 4 — move to target directory
if (!rename($tmpPath, $pharPath)) {
    @unlink($tmpPath);
    http_response_code(500);
    exit("Failed to move composer.phar to target directory.");
}

@chmod($pharPath, 0755);

$sizeMB = number_format(filesize($pharPath) / 1024 / 1024, 2);
exit("✓ composer.phar ({$sizeMB} MB) saved to project root{$verifiedNote}");
