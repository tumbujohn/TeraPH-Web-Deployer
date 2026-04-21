<?php
// =============================================================================
// Auth — Session-based authentication
// =============================================================================

class Auth
{
    /**
     * Checks whether the current session is authenticated.
     * Also enforces the idle session timeout.
     */
    public static function check(): bool
    {
        if (empty($_SESSION['authenticated'])) {
            return false;
        }

        // Idle timeout check
        $lastActivity = $_SESSION['last_activity'] ?? 0;
        if ((time() - $lastActivity) > SESSION_TIMEOUT) {
            self::logout();
            return false;
        }

        $_SESSION['last_activity'] = time();
        return true;
    }

    /**
     * Redirects to login if not authenticated. Use at the top of every
     * protected page.
     */
    public static function require(): void
    {
        if (!self::check()) {
            header('Location: login.php');
            exit;
        }
    }

    /**
     * Attempts to log in with the given credentials.
     *
     * @return bool  true on success, false on failure
     */
    public static function attempt(string $username, string $password): bool
    {
        if ($username !== APP_USERNAME) {
            return false;
        }

        if (!password_verify($password, APP_PASSWORD_HASH)) {
            return false;
        }

        session_regenerate_id(true);

        $_SESSION['authenticated']  = true;
        $_SESSION['username']       = $username;
        $_SESSION['last_activity']  = time();

        return true;
    }

    /**
     * Destroys the current session and logs the user out.
     */
    public static function logout(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();
    }

    /**
     * Returns the username of the currently authenticated user.
     */
    public static function user(): string
    {
        return $_SESSION['username'] ?? 'unknown';
    }
}
