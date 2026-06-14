<?php
// =============================================================================
// Terminal Execute Endpoint (Streaming POST)
// Receives a single shell command and streams its output dynamically.
// =============================================================================

// Prevent buffering so data trickles down via Chunked Transfer
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}
@ini_set('zlib.output_compression', '0');
@ini_set('implicit_flush', '1');
for ($i = 0; $i < ob_get_level(); $i++) {
    @ob_end_flush();
}
@ob_implicit_flush(1);

require_once __DIR__ . '/../app/helpers.php';
app_boot();
Auth::require();

// PRAC.3 — site-wide terminal kill-switch
if (!defined('TERMINAL_ENABLED') || !TERMINAL_ENABLED) {
    http_response_code(403);
    exit("Terminal is disabled on this installation.\n");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit("Method not allowed.");
}

csrf_verify();

$projectId = (int) ($_POST['project_id'] ?? 0);
$command   = trim($_POST['cmd'] ?? '');

if (!$projectId || !$command) {
    http_response_code(400);
    exit("Project ID and command are required.\n");
}

$project = Project::find($projectId);
if (!$project) {
    http_response_code(404);
    exit("Project not found.\n");
}

// PRAC.3 — per-project terminal gate
if (empty($project['terminal_enabled'])) {
    http_response_code(403);
    exit("Terminal is disabled for this project.\n");
}

$cwd = $project['target_path'];
if (!is_dir($cwd)) {
    // Target does not exist yet; fall back to a safe writable directory
    $cwd = TMP_PATH;
}

// PRAC.3 — resolve {composer} placeholder so admins can type it naturally
$hookRunner = new HookRunner();
$command    = str_replace('{composer}', $hookRunner->resolveComposer($cwd), $command);

// PRAC.3 — write audit record (DB preferred, flat-file fallback)
$auditUser = $_SESSION['username'] ?? 'unknown';
$auditIp   = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$auditAt   = date('Y-m-d H:i:s');
$logId     = null;

try {
    $pdo  = Database::connect();
    $stmt = $pdo->prepare("
        INSERT INTO terminal_logs (project_id, user, ip, command, executed_at)
        VALUES (:project_id, :user, :ip, :command, :executed_at)
    ");
    $stmt->execute([
        ':project_id'  => $projectId,
        ':user'        => $auditUser,
        ':ip'          => $auditIp,
        ':command'     => $command,
        ':executed_at' => $auditAt,
    ]);
    $logId = (int) $pdo->lastInsertId();
} catch (Throwable) {
    $flatLog = defined('LOG_PATH')
        ? LOG_PATH . '/terminal_audit.log'
        : dirname(__DIR__) . '/storage/logs/terminal_audit.log';
    @file_put_contents(
        $flatLog,
        "[{$auditAt}] [{$auditUser}@{$auditIp}] project={$projectId} cmd={$command}\n",
        FILE_APPEND | LOCK_EX
    );
}

// ---------------------------------------------------------------------------
// Output headers
// ---------------------------------------------------------------------------
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

echo "➜ $command\n";
@ob_flush(); flush();

// ---------------------------------------------------------------------------
// Launch process
// ---------------------------------------------------------------------------
$env      = null;
$shellCmd = (PHP_OS_FAMILY === 'Windows')
    ? 'cmd.exe /c "' . $command . '"'
    : $command;

$descriptorspec = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

$process = proc_open($shellCmd, $descriptorspec, $pipes, $cwd, $env);

if (!is_resource($process)) {
    echo "\n[ERROR] Failed to start process.\n";
    flush();
    exit;
}

fclose($pipes[0]);
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);

$startTime  = time();
$maxRuntime = defined('TERMINAL_TIMEOUT') ? (int) TERMINAL_TIMEOUT : 600;
$exitCode   = -1;

// ---------------------------------------------------------------------------
// Stream loop
// ---------------------------------------------------------------------------
while (true) {
    $status  = proc_get_status($process);
    $running = $status['running'];

    if (time() - $startTime > $maxRuntime) {
        echo "\n[ERROR] Execution timed out after {$maxRuntime}s.\n";
        break;
    }

    $out = fread($pipes[1], 8192);
    $err = fread($pipes[2], 8192);

    if ($out !== false && $out !== '') { echo $out; @ob_flush(); flush(); }
    if ($err !== false && $err !== '') { echo $err; @ob_flush(); flush(); }

    if (!$running && feof($pipes[1]) && feof($pipes[2])) {
        $exitCode = (int) ($status['exitcode'] ?? -1);
        break;
    }

    usleep(50000); // 50 ms polling

    if (connection_aborted()) {
        break;
    }
}

// ---------------------------------------------------------------------------
// Cleanup
// ---------------------------------------------------------------------------
@proc_terminate($process);
@fclose($pipes[1]);
@fclose($pipes[2]);
if ($exitCode === -1) {
    $exitCode = proc_close($process);
} else {
    proc_close($process);
}

// Back-fill exit code in the audit record
if ($logId !== null) {
    try {
        $pdo->prepare("UPDATE terminal_logs SET exit_code = :code WHERE id = :id")
            ->execute([':code' => $exitCode, ':id' => $logId]);
    } catch (Throwable) {}
}

echo "\n[Process exited with code: {$exitCode}]\n";
@ob_flush(); flush();
