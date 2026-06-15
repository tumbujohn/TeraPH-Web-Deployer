<?php
// =============================================================================
// HookRunner — Executes shell commands for deploy hooks and the web terminal
// =============================================================================
// Central service for all proc_open-based command execution. Used by:
//   - DeployService  (pre_deploy_hooks / post_deploy_hooks pipeline steps)
//   - terminal_execute.php  (resolveComposer resolution)
// =============================================================================

class HookRunner
{
    /**
     * Resolves the correct composer invocation for a given working directory.
     *
     * Resolution order:
     *   1. {$cwd}/composer.phar exists → 'php composer.phar'
     *   2. Global 'composer' found in PATH → 'composer'
     *   3. Fallback → 'php composer.phar' (will fail naturally if not present)
     */
    public function resolveComposer(string $cwd): string
    {
        if (file_exists(rtrim($cwd, '/\\') . '/composer.phar')) {
            return 'php composer.phar';
        }

        $which = PHP_OS_FAMILY === 'Windows'
            ? @shell_exec('where composer 2>nul')
            : @shell_exec('which composer 2>/dev/null');

        return !empty(trim($which ?? '')) ? 'composer' : 'php composer.phar';
    }

    /**
     * Builds a safe environment array for proc_open.
     *
     * Inherits the current process environment (preserving PATH, PHPRC, etc.)
     * and ensures COMPOSER_HOME is set — web server processes often run without
     * HOME, which causes Composer to abort with "HOME env var must be set".
     *
     * @return array<string, string>
     */
    public static function buildEnv(): array
    {
        $env = getenv() ?: [];

        if (empty($env['HOME']) && empty($env['COMPOSER_HOME'])) {
            $base                   = defined('TMP_PATH') ? TMP_PATH : sys_get_temp_dir();
            $env['HOME']            = $base;
            $env['COMPOSER_HOME']   = $base . '/composer';
        } elseif (empty($env['COMPOSER_HOME'])) {
            $base                   = defined('TMP_PATH') ? TMP_PATH : sys_get_temp_dir();
            $env['COMPOSER_HOME']   = $base . '/composer';
        }

        return $env;
    }

    /**
     * Runs a single shell command in $cwd, streaming output via $logger callback.
     *
     * The {composer} placeholder in $command is resolved via resolveComposer().
     * Blank lines and lines starting with # are skipped.
     *
     * @param  callable $logger  fn(string $chunk): void — receives stdout/stderr output
     * @return int  Process exit code (0 = success)
     */
    public function run(string $command, string $cwd, callable $logger): int
    {
        $command  = str_replace('{composer}', $this->resolveComposer($cwd), $command);
        $shellCmd = PHP_OS_FAMILY === 'Windows'
            ? 'cmd.exe /c "' . $command . '"'
            : $command;

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($shellCmd, $descriptors, $pipes, $cwd, self::buildEnv());

        if (!is_resource($process)) {
            $logger('[ERROR] Failed to start process: ' . $command . "\n");
            return 1;
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $maxRuntime = defined('HOOK_TIMEOUT') ? (int) HOOK_TIMEOUT : 300;
        $startTime  = time();

        while (true) {
            $status = proc_get_status($process);

            if (time() - $startTime > $maxRuntime) {
                $logger('[ERROR] Hook timed out after ' . $maxRuntime . "s.\n");
                break;
            }

            $out = fread($pipes[1], 8192) ?: '';
            $err = fread($pipes[2], 8192) ?: '';

            if ($out !== '') $logger($out);
            if ($err !== '') $logger($err);

            if (!$status['running'] && feof($pipes[1]) && feof($pipes[2])) {
                break;
            }

            usleep(50000);
        }

        @proc_terminate($process);
        @fclose($pipes[1]);
        @fclose($pipes[2]);

        return proc_close($process);
    }

    /**
     * Runs a list of hook commands sequentially.
     * Stops and returns the failing exit code on the first non-zero result.
     * Blank lines and lines starting with # are skipped silently.
     *
     * @param  string[]  $commands
     * @param  callable  $logger   fn(string $chunk): void
     * @return int  0 if all passed, first non-zero exit code otherwise
     */
    public function runAll(array $commands, string $cwd, callable $logger): int
    {
        foreach ($commands as $cmd) {
            $cmd = trim($cmd);
            if ($cmd === '' || str_starts_with($cmd, '#')) {
                continue;
            }

            $logger('$ ' . $cmd . "\n");
            $exit = $this->run($cmd, $cwd, $logger);

            if ($exit !== 0) {
                $logger('[ERROR] Command exited ' . $exit . ': ' . $cmd . "\n");
                return $exit;
            }
        }

        return 0;
    }
}
