<?php
// =============================================================================
// Shared Helper Functions
// =============================================================================

// =============================================================================
// Self-Protection Helpers (PRAC.1)
// =============================================================================

/**
 * Returns true if the deployer's own root directory lives inside $targetPath.
 * Used by DeployService and BackupService to prevent self-deletion.
 */
function is_deployer_inside_target(string $targetPath): bool
{
    $deployerRoot = defined('DEPLOYER_ROOT') ? DEPLOYER_ROOT : dirname(__DIR__);
    $deployerReal = realpath($deployerRoot);
    $targetReal   = realpath($targetPath);

    if ($deployerReal === false || $targetReal === false) {
        return false;
    }

    $deployerNorm = rtrim(str_replace(DIRECTORY_SEPARATOR, '/', $deployerReal), '/');
    $targetNorm   = rtrim(str_replace(DIRECTORY_SEPARATOR, '/', $targetReal), '/');

    return str_starts_with($deployerNorm . '/', $targetNorm . '/');
}

/**
 * Returns the relative path of the deployer root within $targetPath.
 * Returns an empty string if the deployer is not inside the target.
 *
 * Example: target = /home/user/public_html, deployer = /home/user/public_html/deployer
 *          → returns 'deployer'
 */
function deployer_relative_path(string $targetPath): string
{
    $deployerRoot = defined('DEPLOYER_ROOT') ? DEPLOYER_ROOT : dirname(__DIR__);
    $deployerReal = realpath($deployerRoot);
    $targetReal   = realpath($targetPath);

    if ($deployerReal === false || $targetReal === false) {
        return '';
    }

    $deployerNorm = rtrim(str_replace(DIRECTORY_SEPARATOR, '/', $deployerReal), '/');
    $targetNorm   = rtrim(str_replace(DIRECTORY_SEPARATOR, '/', $targetReal), '/');

    if (!str_starts_with($deployerNorm . '/', $targetNorm . '/')) {
        return '';
    }

    return ltrim(substr($deployerNorm, strlen($targetNorm)), '/');
}

// =============================================================================
// Deploy Strategy Detection (PRAC.2)
// =============================================================================

/**
 * Resolves the effective deploy strategy for this installation.
 *
 * - 'symlink' → uses atomic symlink-based releases (requires host support)
 * - 'copy'    → uses enhanced copy-in-place (safe fallback for shared hosting)
 *
 * When DEPLOY_STRATEGY = 'auto', the result of a one-time symlink capability
 * test is cached in storage/deploy_strategy.txt.
 */
function detect_deploy_strategy(): string
{
    $configured = defined('DEPLOY_STRATEGY') ? DEPLOY_STRATEGY : 'auto';

    if ($configured === 'symlink') return 'symlink';
    if ($configured === 'copy')    return 'copy';

    // Auto-detect: check cached result first
    $cacheFile = defined('STORAGE_PATH')
        ? STORAGE_PATH . '/deploy_strategy.txt'
        : dirname(__DIR__) . '/storage/deploy_strategy.txt';

    if (file_exists($cacheFile)) {
        $cached = trim((string) file_get_contents($cacheFile));
        if (in_array($cached, ['symlink', 'copy'], true)) {
            return $cached;
        }
    }

    // Test symlink capability using a unique name to avoid race conditions
    $tmpDir     = defined('TMP_PATH') ? TMP_PATH : sys_get_temp_dir();
    $pid        = getmypid() ?: mt_rand(1000, 9999);
    $testTarget = $tmpDir . '/.symtest_target_' . $pid;
    $testLink   = $tmpDir . '/.symtest_link_' . $pid;

    @file_put_contents($testTarget, '');
    $canSymlink = @symlink($testTarget, $testLink) && is_link($testLink);
    @unlink($testTarget);
    @unlink($testLink);

    $strategy = $canSymlink ? 'symlink' : 'copy';
    @file_put_contents($cacheFile, $strategy);

    return $strategy;
}

/**
 * Bootstraps the application: loads config, runs migrations, starts session.
 * Called once from every public entry point.
 */
function app_boot(): void
{
    $root = dirname(__DIR__);

    // Bootstrap loads config + all classes (single source of truth)
    require_once $root . '/app/bootstrap.php';

    // Run migrations (idempotent — safe to call on every request).
    // Output is buffered and discarded so no migration echo can corrupt AJAX responses.
    static $migrated = false;
    if (!$migrated) {
        ob_start();
        require_once $root . '/migrations/001_initial_schema.php';
        ob_end_clean();
        $migrated = true;
    }

    session_name(SESSION_NAME);
    if (session_status() === PHP_SESSION_NONE) {
        session_start([
            'cookie_httponly' => true,
            'cookie_samesite' => 'Strict',
        ]);
    }
}

// =============================================================================
// CSRF Protection
// =============================================================================

/**
 * Generates (or retrieves) the CSRF token for the current session.
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Renders a hidden CSRF input field.
 */
function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">';
}

/**
 * Validates the CSRF token from a POST request. Terminates on failure.
 */
function csrf_verify(): void
{
    $token = $_POST['_csrf'] ?? '';
    if (!hash_equals(csrf_token(), $token)) {
        http_response_code(403);
        json_error('CSRF validation failed.', 403);
        exit;
    }
}

// =============================================================================
// Output Helpers
// =============================================================================

/**
 * Escapes a value for safe HTML output.
 */
function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Sends a JSON success response and exits.
 *
 * @param mixed $data
 */
function json_ok(mixed $data = null, string $message = 'OK'): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'message' => $message, 'data' => $data]);
    exit;
}

/**
 * Sends a JSON error response and exits.
 */
function json_error(string $message, int $code = 400): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => $message, 'data' => null]);
    exit;
}

// =============================================================================
// Flash Messages
// =============================================================================

/**
 * Stores a flash message in the session.
 */
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/**
 * Retrieves and clears all flash messages.
 *
 * @return array<int, array{type: string, message: string}>
 */
function get_flash(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

// =============================================================================
// Formatting Helpers
// =============================================================================

/**
 * Formats a byte count into a human-readable string (KB, MB, GB).
 */
function format_bytes(int $bytes): string
{
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    }
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    }
    return $bytes . ' B';
}

/**
 * Returns a human-friendly relative time string (e.g., "2 hours ago").
 */
function time_ago(?string $datetime): string
{
    if (empty($datetime)) {
        return 'Never';
    }

    $diff = time() - strtotime($datetime);

    return match (true) {
        $diff < 60      => 'Just now',
        $diff < 3600    => floor($diff / 60) . 'm ago',
        $diff < 86400   => floor($diff / 3600) . 'h ago',
        $diff < 604800  => floor($diff / 86400) . 'd ago',
        default         => date('M j, Y', strtotime($datetime)),
    };
}

// =============================================================================
// .env Encryption Helpers
// =============================================================================

/**
 * Derives a 32-byte AES key from SECRET_KEY (or APP_PASSWORD_HASH fallback).
 */
function env_encryption_key(): string
{
    $secret = (defined('SECRET_KEY') && SECRET_KEY !== '') ? SECRET_KEY : APP_PASSWORD_HASH;
    return substr(hash('sha256', $secret . ':teraph:env', true), 0, 32);
}

/**
 * Encrypts a plain-text env value with AES-256-CBC.
 * Returns base64-encoded IV + ciphertext, or empty string for empty input.
 */
function env_encrypt(string $value): string
{
    if ($value === '') return '';
    $key = env_encryption_key();
    $iv  = random_bytes(16);
    $enc = openssl_encrypt($value, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $enc);
}

/**
 * Decrypts a value produced by env_encrypt(). Returns empty string on failure.
 */
function env_decrypt(string $enc): string
{
    if ($enc === '') return '';
    $key  = env_encryption_key();
    $raw  = base64_decode($enc, true);
    if ($raw === false || strlen($raw) <= 16) return '';
    $iv   = substr($raw, 0, 16);
    $data = substr($raw, 16);
    $dec  = openssl_decrypt($data, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    return $dec !== false ? $dec : '';
}

/**
 * Parses a .env / .env.example string and returns an ordered list of key names.
 * Blank lines and comment lines are skipped; only KEY= lines are returned.
 *
 * @return string[]
 */
function env_parse_keys(string $template): array
{
    $keys = [];
    foreach (explode("\n", $template) as $line) {
        $line = rtrim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        $eq = strpos($line, '=');
        if ($eq === false) continue;
        $key = trim(substr($line, 0, $eq));
        if ($key !== '' && preg_match('/^[A-Z0-9_]+$/i', $key)) {
            $keys[] = $key;
        }
    }
    return array_values(array_unique($keys));
}

/**
 * Renders a .env file string by merging stored values into a template.
 * Lines that match KEY= (with any value) have their value replaced by $values[$key].
 * Comment and blank lines are preserved as-is.
 *
 * @param string   $template  The .env.example template string
 * @param string[] $values    Map of KEY => plain-text value
 */
function env_render(string $template, array $values): string
{
    $lines  = explode("\n", $template);
    $output = [];
    foreach ($lines as $line) {
        $trimmed = rtrim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            $output[] = $trimmed;
            continue;
        }
        $eq = strpos($trimmed, '=');
        if ($eq === false) {
            $output[] = $trimmed;
            continue;
        }
        $key = trim(substr($trimmed, 0, $eq));
        if (isset($values[$key])) {
            $val = $values[$key];
            // Quote values that contain spaces or special chars
            if (preg_match('/[\s"\'#]/', $val)) {
                $val = '"' . addcslashes($val, '"\\') . '"';
            }
            $output[] = $key . '=' . $val;
        } else {
            $output[] = $trimmed;
        }
    }
    return implode("\n", $output);
}

/**
 * Returns a CSS class name for a deployment status badge.
 */
function status_class(string $status): string
{
    return match ($status) {
        'success' => 'badge-success',
        'failed'  => 'badge-danger',
        'running' => 'badge-running',
        'pending' => 'badge-warning',
        default   => 'badge-neutral',
    };
}

/**
 * Returns a label string for a deployment status.
 */
function status_label(string $status): string
{
    return match ($status) {
        'success' => '● Live',
        'failed'  => '⚠ Failed',
        'running' => '⟳ Deploying',
        'pending' => '○ Pending',
        default   => '○ Never deployed',
    };
}
