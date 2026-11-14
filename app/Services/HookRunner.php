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
        // The PHP that runs composer.phar: CLI_PHP_PATH when set, else whatever `php` is on PATH
        $bin = self::cliPhp();
        $php = $bin === null ? 'php' : (preg_match('/\s/', $bin) ? escapeshellarg($bin) : $bin);

        if (file_exists(rtrim($cwd, '/\\') . '/composer.phar')) {
            return $php . ' composer.phar';
        }

        $which = PHP_OS_FAMILY === 'Windows'
            ? @shell_exec('where composer 2>nul')
            : @shell_exec('which composer 2>/dev/null');

        return !empty(trim($which ?? '')) ? 'composer' : $php . ' composer.phar';
    }

    /**
     * The PHP CLI binary that hooks and the terminal should use, from CLI_PHP_PATH in
     * config.php — or null to use whatever `php` the web server's PATH resolves to
     * (the behavior before the constant existed).
     *
     * Needed where the web server's PATH finds a different, often older, `php` than the
     * one the site runs on: typical on cPanel, where the default CLI PHP is not the
     * domain's MultiPHP version, so `php composer.phar install` fails platform checks even
     * though the Settings page and an SSH login both show the newer PHP.
     *
     * A path that is not an executable file is ignored (Settings flags it) rather than
     * breaking every command.
     */
    public static function cliPhp(): ?string
    {
        if (!defined('CLI_PHP_PATH')) {
            return null;
        }
        $path = trim((string) CLI_PHP_PATH);
        return ($path !== '' && is_file($path) && is_executable($path)) ? $path : null;
    }

    /**
     * The PHP CLI that belongs to the PHP running this web request, if it can be found and
     * is the same major.minor version — what CLI_PHP_PATH should usually be set to.
     *
     * Looks beside PHP_BINDIR, beside the running binary (CLI server), and in the `bin/` next
     * to an FPM `sbin/php-fpm` (cPanel: /opt/cpanel/ea-phpNN/root/usr/{sbin,bin}). PHP_BINDIR
     * alone is not enough: Windows builds hardcode it.
     */
    public static function webPhpCli(): ?string
    {
        $exe  = PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php';
        $dirs = [PHP_BINDIR];
        if (PHP_BINARY !== '') {
            $dirs[] = dirname(PHP_BINARY);
            $dirs[] = dirname(dirname(PHP_BINARY)) . DIRECTORY_SEPARATOR . 'bin';
        }
        $want = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;

        foreach (array_unique($dirs) as $dir) {
            $candidate = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $exe;
            if (is_file($candidate) && is_executable($candidate)) {
                $v = self::phpVersionOf($candidate);
                if ($v !== null && str_starts_with($v, $want . '.')) {
                    return $candidate;
                }
            }
        }
        return null;
    }

    /**
     * Version string of the PHP a command would get (`php` on PATH, or an explicit binary),
     * resolved with the same environment hooks and the terminal run in. Null if it can't be
     * determined (proc_open disabled, no such command, unexpected output).
     */
    public static function phpVersionOf(string $phpCommand): ?string
    {
        if (!function_exists('proc_open')) {
            return null;
        }
        $cmd = (preg_match('/\s/', $phpCommand) ? escapeshellarg($phpCommand) : $phpCommand)
             . ' -r ' . (PHP_OS_FAMILY === 'Windows' ? '"echo PHP_VERSION;"' : "'echo PHP_VERSION;'");

        $proc = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, self::buildEnv());
        if (!is_resource($proc)) {
            return null;
        }
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        $out = trim($out);
        return preg_match('/^\d+\.\d+\.\d+\S*$/', $out) ? $out : null;
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

        // CLI_PHP_PATH: put the chosen PHP's directory first on PATH so `php`, a `composer`
        // script (#!/usr/bin/env php) and anything they spawn all resolve to the same binary.
        if ($bin = self::cliPhp()) {
            $key = 'PATH';
            foreach (array_keys($env) as $k) {
                if (strcasecmp((string) $k, 'PATH') === 0) {   // 'Path' on Windows
                    $key = (string) $k;
                    break;
                }
            }
            $env[$key] = dirname($bin) . PATH_SEPARATOR . ($env[$key] ?? '');
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
