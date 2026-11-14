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
    // =========================================================================
    // Which PHP do commands get?
    // =========================================================================
    // Hooks and the terminal run as child processes of the web server's PHP, so a
    // bare `php` is whatever the web server's PATH finds — often the host's default
    // CLI, not the PHP the site runs on (cPanel/DirectAdmin multi-PHP hosts install
    // one CLI per version; Settings shows the web PHP, SSH has its own PATH).
    //
    // resolvePhp() decides, in this order:
    //   1. the project's own binary (projects.php_binary)
    //   2. CLI_PHP_PATH in config.php: a path, or 'system' to leave PATH alone
    //   3. automatic: the PHP CLI belonging to the PHP serving this site, when the
    //      `php` on PATH is a different major.minor version
    // A chosen binary's directory is put first on PATH, so `php`, `composer` and
    // anything they spawn all resolve to it.
    // =========================================================================

    /**
     * @return array{binary: ?string, source: string, ignored: string[]}
     *         binary  = absolute path to put first on PATH, or null to leave PATH untouched
     *         source  = 'project' | 'config' | 'auto' | 'system'
     *         ignored = settings that were set but unusable ('project', 'config'), for Settings to flag
     */
    public static function resolvePhp(?string $projectBinary = null): array
    {
        $ignored = [];

        $project = trim((string) $projectBinary);
        if ($project !== '') {
            if (self::usableBinary($project)) {
                return ['binary' => $project, 'source' => 'project', 'ignored' => $ignored];
            }
            $ignored[] = 'project';
        }

        if (defined('CLI_PHP_PATH')) {
            $configured = trim((string) CLI_PHP_PATH);
            if (strcasecmp($configured, 'system') === 0) {
                return ['binary' => null, 'source' => 'system', 'ignored' => $ignored];
            }
            if ($configured !== '') {
                if (self::usableBinary($configured)) {
                    return ['binary' => $configured, 'source' => 'config', 'ignored' => $ignored];
                }
                $ignored[] = 'config';
            }
        }

        $auto = self::autoPhp();
        return ['binary' => $auto, 'source' => $auto !== null ? 'auto' : 'system', 'ignored' => $ignored];
    }

    /**
     * An absolute path to an executable file with no control characters — the only kind of
     * value accepted for a PHP binary (it ends up on PATH and in command lines).
     */
    public static function usableBinary(string $path): bool
    {
        $absolute = str_starts_with($path, '/') || preg_match('~^[A-Za-z]:[\\\\/]~', $path) === 1;
        return $absolute
            && !preg_match('/[\x00-\x1f]/', $path)
            && is_file($path)
            && is_executable($path);
    }

    /**
     * The PHP CLI of the PHP serving this request — but only when the `php` on PATH is a
     * different major.minor version (otherwise PATH is already right and is left alone).
     * Computed once per request: it spawns `php` to compare versions.
     */
    private static function autoPhp(): ?string
    {
        static $done = false, $result = null;
        if ($done) {
            return $result;
        }
        $done = true;

        $web = self::webPhpCli();
        if ($web !== null) {
            $system = self::systemPhpVersion();
            if ($system === null || !str_starts_with($system, PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.')) {
                $result = $web;
            }
        }
        return $result;
    }

    /**
     * Version of the `php` that the web server's own PATH finds (no override applied).
     */
    public static function systemPhpVersion(): ?string
    {
        static $done = false, $version = null;
        if (!$done) {
            $done    = true;
            $version = self::phpVersionOf('php');
        }
        return $version;
    }

    /**
     * The PHP CLI that belongs to the PHP running this web request, if it can be found and
     * is the same major.minor version.
     *
     * Looks beside PHP_BINDIR, beside the running binary (CLI server), and in the `bin/` next
     * to an FPM `sbin/php-fpm` (cPanel: /opt/cpanel/ea-phpNN/root/usr/{sbin,bin}). PHP_BINDIR
     * alone is not enough: Windows builds hardcode it.
     */
    public static function webPhpCli(): ?string
    {
        static $done = false, $found = null;
        if ($done) {
            return $found;
        }
        $done = true;

        $exe  = PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php';
        $dirs = [PHP_BINDIR];
        if (PHP_BINARY !== '') {
            $dirs[] = dirname(PHP_BINARY);
            $dirs[] = dirname(dirname(PHP_BINARY)) . DIRECTORY_SEPARATOR . 'bin';
        }
        $want = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.';

        foreach (array_unique($dirs) as $dir) {
            $candidate = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $exe;
            if (is_file($candidate) && is_executable($candidate)) {
                $v = self::phpVersionOf($candidate);
                if ($v !== null && str_starts_with($v, $want)) {
                    return $found = $candidate;
                }
            }
        }
        return null;
    }

    /**
     * PHP CLI binaries found in the usual places on this host. Paths only — nothing is
     * executed — so it is cheap enough for the project form's suggestion list.
     *
     * @param  string[] $extraPatterns Additional glob patterns (used by tests)
     * @return string[]
     */
    public static function discoverPhpBinaries(array $extraPatterns = []): array
    {
        $patterns = array_merge([
            '/opt/cpanel/ea-php*/root/usr/bin/php',   // cPanel EasyApache
            '/opt/alt/php*/usr/bin/php',              // CloudLinux alt-php
            '/usr/local/php*/bin/php',                // DirectAdmin
            '/opt/plesk/php/*/bin/php',               // Plesk
            '/opt/remi/php*/root/usr/bin/php',        // Remi
            '/usr/local/bin/php', '/usr/bin/php',
            '/usr/bin/php[0-9]*', '/usr/local/bin/php[0-9]*',   // php8.3, php83
        ], $extraPatterns);

        $found = [];
        $add   = function (string $path) use (&$found): void {
            if (preg_match('~[\\\\/]php(\d[\d.]*)?(\.exe)?$~i', $path) && self::usableBinary($path)) {
                $found[(string) (realpath($path) ?: $path)] = $path;
            }
        };
        foreach ($patterns as $pattern) {
            foreach ((array) @glob($pattern) as $path) {
                $add((string) $path);
            }
        }
        if (($web = self::webPhpCli()) !== null) {
            $add($web);
        }

        $paths = array_values($found);
        sort($paths);
        return array_slice($paths, 0, 30);
    }

    /**
     * One-line description of the PHP a command would get, for deploy logs and Settings:
     * "8.3.36 (/usr/local/php83/bin/php, automatic)".
     */
    public static function describePhp(?string $projectBinary = null): string
    {
        $sel     = self::resolvePhp($projectBinary);
        $version = self::phpVersionOf($sel['binary'] ?? 'php');
        $label   = ['project' => 'project setting', 'config' => 'CLI_PHP_PATH', 'auto' => 'automatic', 'system' => "php from the web server's PATH"][$sel['source']];
        return ($version ?? 'unknown version') . ' (' . ($sel['binary'] ?? 'php') . ', ' . $label . ')';
    }

    /**
     * Version string of a PHP binary (or of `php` on PATH when given the bare word), resolved
     * with the web server's own environment. Null if it can't be determined (proc_open
     * disabled, no such command, unexpected output).
     */
    public static function phpVersionOf(string $phpCommand): ?string
    {
        if (!function_exists('proc_open')) {
            return null;
        }
        $cmd = (preg_match('/\s/', $phpCommand) ? escapeshellarg($phpCommand) : $phpCommand)
             . ' -r ' . (PHP_OS_FAMILY === 'Windows' ? '"echo PHP_VERSION;"' : "'echo PHP_VERSION;'");

        $proc = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, self::baseEnv());
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
     * Resolves the correct composer invocation for a given working directory.
     *
     * Resolution order:
     *   1. {$cwd}/composer.phar exists → '<php> composer.phar'
     *   2. Global 'composer' found in PATH → 'composer'
     *   3. Fallback → '<php> composer.phar' (will fail naturally if not present)
     *
     * <php> is the PHP chosen by resolvePhp() — so composer runs under the same version
     * as the rest of the hook — or plain `php` when PATH is left alone.
     */
    public function resolveComposer(string $cwd, ?string $phpBinary = null): string
    {
        $bin = self::resolvePhp($phpBinary)['binary'];
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
     * Inherited process environment, with COMPOSER_HOME ensured — web server processes often
     * run without HOME, which makes Composer abort with "HOME env var must be set". No PHP
     * override applied: this is what `php` resolves against by default.
     *
     * @return array<string, string>
     */
    private static function baseEnv(): array
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
     * Builds the environment array for proc_open: the inherited environment plus, when
     * resolvePhp() picks a binary, that PHP's directory first on PATH.
     *
     * @param  string|null $phpBinary The project's own PHP binary, if it has one
     * @return array<string, string>
     */
    public static function buildEnv(?string $phpBinary = null): array
    {
        $env = self::baseEnv();

        $binary = self::resolvePhp($phpBinary)['binary'];
        if ($binary !== null) {
            $key = 'PATH';
            foreach (array_keys($env) as $k) {
                if (strcasecmp((string) $k, 'PATH') === 0) {   // 'Path' on Windows
                    $key = (string) $k;
                    break;
                }
            }
            $env[$key] = self::pathDirFor($binary) . PATH_SEPARATOR . ($env[$key] ?? '');
        }

        return $env;
    }

    /**
     * Directory to put on PATH so that `php` resolves to $binary. A binary already named
     * `php` is used in place. Any other name (php8.3, ea-php83, a wrapper) is exposed as `php`
     * through a small shim directory: a symlink, or a one-line wrapper script where symlinks
     * are not allowed. If neither can be created the binary's own directory is used.
     */
    private static function pathDirFor(string $binary): string
    {
        $name = strtolower(basename($binary));
        if ($name === 'php' || $name === 'php.exe') {
            return dirname($binary);
        }

        $isWin = PHP_OS_FAMILY === 'Windows';
        $base  = defined('TMP_PATH') ? TMP_PATH : sys_get_temp_dir();
        $dir   = rtrim($base, '/\\') . DIRECTORY_SEPARATOR . 'phpbin' . DIRECTORY_SEPARATOR . substr(md5($binary), 0, 12);
        $shim  = $dir . DIRECTORY_SEPARATOR . ($isWin ? 'php.bat' : 'php');

        if (!is_file($shim) && !is_link($shim)) {
            @mkdir($dir, 0755, true);
            if ($isWin || !@symlink($binary, $shim)) {
                $script = $isWin
                    ? "@echo off\r\ncall \"{$binary}\" %*\r\n"
                    : "#!/bin/sh\nexec " . escapeshellarg($binary) . " \"\$@\"\n";
                @file_put_contents($shim, $script);
                @chmod($shim, 0755);
            }
        }

        return (is_file($shim) || is_link($shim)) ? $dir : dirname($binary);
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
    public function run(string $command, string $cwd, callable $logger, ?string $phpBinary = null): int
    {
        $command  = str_replace('{composer}', $this->resolveComposer($cwd, $phpBinary), $command);
        $shellCmd = PHP_OS_FAMILY === 'Windows'
            ? 'cmd.exe /c "' . $command . '"'
            : $command;

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($shellCmd, $descriptors, $pipes, $cwd, self::buildEnv($phpBinary));

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
    public function runAll(array $commands, string $cwd, callable $logger, ?string $phpBinary = null): int
    {
        foreach ($commands as $cmd) {
            $cmd = trim($cmd);
            if ($cmd === '' || str_starts_with($cmd, '#')) {
                continue;
            }

            $logger('$ ' . $cmd . "\n");
            $exit = $this->run($cmd, $cwd, $logger, $phpBinary);

            if ($exit !== 0) {
                $logger('[ERROR] Command exited ' . $exit . ': ' . $cmd . "\n");
                return $exit;
            }
        }

        return 0;
    }
}
