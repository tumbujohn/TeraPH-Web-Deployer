<?php
// =============================================================================
// Shared Helper Functions
// =============================================================================

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
