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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit("Method not allowed.");
}

csrf_verify();

$projectId = (int) ($_POST['project_id'] ?? 0);
$command   = trim($_POST['cmd'] ?? '');

if (!$projectId || !$command) {
    http_response_code(400);
    exit("Project ID and Command are required.\n");
}

$project = Project::find($projectId);
if (!$project) {
    http_response_code(404);
    exit("Project not found.\n");
}

$cwd = $project['target_path'];
if (!is_dir($cwd)) {
    // If target path doesn't exist, start in TMP_PATH as fallback
    $cwd = TMP_PATH;
}

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

echo "➜ $command\n";
@ob_flush(); flush();

// We inherit the system environment safely
$env = null;

// Ensure Windows commands run safely through the cmd.exe invocation
$shellCmd = (PHP_OS_FAMILY === 'Windows')
    ? 'cmd.exe /c "' . $command . '"'
    : $command;

$descriptorspec = [
    0 => ["pipe", "r"], // STDIN
    1 => ["pipe", "w"], // STDOUT
    2 => ["pipe", "w"]  // STDERR
];

$process = proc_open($shellCmd, $descriptorspec, $pipes, $cwd, $env);

if (!is_resource($process)) {
    echo "\n[ERROR] Failed to start process.\n";
    flush();
    exit;
}

// STDIN is unnecessary for stateless commands, close immediately
fclose($pipes[0]);

stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);

$startTime = time();
$maxRuntime = 600; // 10 minutes max per command

while (true) {
    $status = proc_get_status($process);
    $running = $status['running'];
    
    // Safety termination
    if (time() - $startTime > $maxRuntime) {
        echo "\n[ERROR] Execution timed out after 10 minutes.\n";
        break;
    }

    $out = '';
    $err = '';
    
    if (isset($pipes[1])) {
        $data = fread($pipes[1], 8192);
        if ($data !== false && $data !== '') $out .= $data;
    }
    
    if (isset($pipes[2])) {
        $data = fread($pipes[2], 8192);
        if ($data !== false && $data !== '') $err .= $data;
    }

    if ($out !== '') {
        echo $out;
        @ob_flush(); flush();
    }
    if ($err !== '') {
        echo $err;
        @ob_flush(); flush();
    }

    if (!$running && feof($pipes[1]) && feof($pipes[2])) {
        break;
    }

    // Polling delay
    usleep(50000); // 50ms
    
    if (connection_aborted()) {
        break; // Frontend disconnected
    }
}

// Cleanup
@proc_terminate($process);
@fclose($pipes[1]);
@fclose($pipes[2]);
$exitCode = proc_close($process);

echo "\n[Process terminated automatically with exit code: $exitCode]\n";
@ob_flush(); flush();
