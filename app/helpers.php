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

    // Run all migrations in order (all are idempotent — safe on every request).
    // Output is buffered so no migration echo can corrupt AJAX responses.
    // Running the full set ensures new migrations are applied automatically
    // after a self-update, without requiring a manual visit to migrate.php.
    static $migrated = false;
    if (!$migrated) {
        ob_start();
        $migFiles = glob($root . '/migrations/*.php');
        if ($migFiles) {
            sort($migFiles);
            foreach ($migFiles as $migFile) {
                require_once $migFile;
            }
        }
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
 * Encrypts a plain-text env value with AES-256-GCM (authenticated encryption).
 * Returns 'g:' + base64(nonce[12] + tag[16] + ciphertext), or '' for empty input.
 * Legacy values encrypted with AES-256-CBC are still readable by env_decrypt().
 */
function env_encrypt(string $value): string
{
    if ($value === '') return '';
    $key   = env_encryption_key();
    $nonce = random_bytes(12);
    $tag   = '';
    $ct    = openssl_encrypt($value, 'AES-256-GCM', $key, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if ($ct === false) {
        // GCM unavailable on this host (very old OpenSSL) — fall back to CBC
        $iv  = random_bytes(16);
        $enc = openssl_encrypt($value, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . (string) $enc);
    }
    return 'g:' . base64_encode($nonce . $tag . $ct);
}

/**
 * Decrypts a value produced by env_encrypt().
 * Supports both AES-256-GCM (prefixed 'g:') and legacy AES-256-CBC (no prefix).
 * Returns empty string on failure or empty input.
 */
function env_decrypt(string $enc): string
{
    return env_decrypt_strict($enc) ?? '';
}

/**
 * Like env_decrypt() but distinguishes failure from an empty value:
 * returns null when a non-empty ciphertext cannot be decrypted (wrong
 * SECRET_KEY, corrupt data) and '' only for genuinely empty input.
 */
function env_decrypt_strict(string $enc): ?string
{
    if ($enc === '') return '';
    $key = env_encryption_key();

    if (str_starts_with($enc, 'g:')) {
        // AES-256-GCM — nonce[12] + tag[16] + ciphertext
        $raw = base64_decode(substr($enc, 2), true);
        if ($raw === false || strlen($raw) <= 28) return null;
        $nonce = substr($raw, 0, 12);
        $tag   = substr($raw, 12, 16);
        $ct    = substr($raw, 28);
        $dec   = openssl_decrypt($ct, 'AES-256-GCM', $key, OPENSSL_RAW_DATA, $nonce, $tag);
        return $dec !== false ? $dec : null;
    }

    // Legacy AES-256-CBC (no prefix) — iv[16] + ciphertext
    $raw = base64_decode($enc, true);
    if ($raw === false || strlen($raw) <= 16) return null;
    $iv  = substr($raw, 0, 16);
    $ct  = substr($raw, 16);
    $dec = openssl_decrypt($ct, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    return $dec !== false ? $dec : null;
}

/**
 * Whether backups should password-protect .env files inside the zip.
 * Controlled by BACKUP_ENCRYPT_ENV in config.php; unencrypted by default and
 * when the constant is absent (configs created before it existed).
 */
function backup_encrypt_env(): bool
{
    return defined('BACKUP_ENCRYPT_ENV') && BACKUP_ENCRYPT_ENV === true;
}

/**
 * Permission mode for the managed .env, from ENV_FILE_MODE in config.php.
 *
 * New installs get 0640 from config.php.example. An install whose config.php predates
 * the constant keeps the historical 0644, so updating never silently removes read access
 * from a web server running as a different user than the deployer. Out-of-range values
 * fall back to 0644 rather than writing an unreadable or world-writable file.
 */
function env_file_mode(): int
{
    if (!defined('ENV_FILE_MODE')) {
        return 0644;
    }
    $mode = (int) ENV_FILE_MODE;
    return ($mode >= 0400 && $mode <= 0644 && ($mode & 0400)) ? $mode : 0644;
}

/**
 * Password for encrypted zip entries, derived from the env encryption key so
 * it needs no extra secret. Changing SECRET_KEY makes the .env files in
 * encrypted backups unrecoverable, the same as the stored env values.
 */
function backup_zip_password(): string
{
    return hash_hmac('sha256', 'teraph:backup', env_encryption_key());
}

/**
 * True for real env files (.env, .env.local, .env.production); false for
 * templates (.env.example, .env.sample, .env.dist), which hold no secrets.
 */
function is_env_secret_file(string $basename): bool
{
    return (bool) preg_match('/^\.env(\..+)?$/', $basename)
        && !preg_match('/\.(example|sample|dist)$/i', $basename);
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
    foreach (env_logical_lines($template) as $line) {
        $line = trim($line);                                    // full trim catches indented comments
        if ($line === '' || str_starts_with($line, '#')) continue;
        $line = (string) preg_replace('/^export\s+/i', '', $line); // strip bash 'export KEY=' prefix
        $eq = strpos($line, '=');
        if ($eq === false) continue;
        $key = rtrim(substr($line, 0, $eq));
        // Accept any non-empty key that has no whitespace, #, or = — covers
        // lowercase, mixed-case, dots, hyphens, etc. used by various frameworks
        if ($key !== '' && !preg_match('/[\s#=]/', $key)) {
            $keys[] = $key;
        }
    }
    return array_values(array_unique($keys));
}

/**
 * Parses a .env / .env.example string and returns an ordered map of key => plain value.
 * Blank lines and comment lines are skipped. Values are unquoted via env_unquote().
 * Keys with empty values are still included (value = '').
 *
 * @return array<string, string>
 */
function env_parse_pairs(string $template): array
{
    $pairs = [];
    foreach (env_logical_lines($template) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        $line = (string) preg_replace('/^export\s+/i', '', $line);
        $eq   = strpos($line, '=');
        if ($eq === false) continue;
        $key = rtrim(substr($line, 0, $eq));
        if ($key === '' || preg_match('/[\s#=]/', $key)) continue;
        if (!array_key_exists($key, $pairs)) {
            $pairs[$key] = env_unquote(substr($line, $eq + 1));
        }
    }
    return $pairs;
}

/**
 * Strips surrounding quotes from a raw .env value and removes trailing inline comments.
 * Handles double-quoted (with escape sequences), single-quoted (literal), and bare values.
 */
function env_unquote(string $raw): string
{
    $v = trim($raw);
    if ($v === '') return '';

    // Double-quoted: "value" — process escape sequences inside
    if ($v[0] === '"') {
        $i     = 1;
        $inner = '';
        $len   = strlen($v);
        while ($i < $len) {
            $c = $v[$i];
            if ($c === '\\' && $i + 1 < $len) {
                $inner .= match($v[$i + 1]) {
                    '"'     => '"',
                    '\\'    => '\\',
                    'n'     => "\n",
                    'r'     => "\r",
                    't'     => "\t",
                    default => '\\' . $v[$i + 1],
                };
                $i += 2;
                continue;
            }
            if ($c === '"') break; // closing quote
            $inner .= $c;
            $i++;
        }
        return $inner;
    }

    // Single-quoted: 'value' — literal, no escaping
    if ($v[0] === "'") {
        $end = strrpos($v, "'");
        return $end > 0 ? substr($v, 1, $end - 1) : substr($v, 1);
    }

    // Bare value — strip trailing inline comment (space followed by #)
    if (($pos = strpos($v, ' #')) !== false) {
        $v = rtrim(substr($v, 0, $pos));
    }
    return $v;
}

/**
 * If the first assignment in $text opens a quoted value that is not closed
 * within $text, returns the quote character; otherwise null.
 * Double quotes honour backslash escapes; single quotes are literal.
 */
function env_open_quote(string $text): ?string
{
    $t = ltrim($text);
    if ($t === '' || $t[0] === '#') return null;
    $t  = (string) preg_replace('/^export\s+/i', '', $t);
    $eq = strpos($t, '=');
    if ($eq === false) return null;
    $v = ltrim(substr($t, $eq + 1));
    if ($v === '' || ($v[0] !== '"' && $v[0] !== "'")) return null;

    $q   = $v[0];
    $len = strlen($v);
    for ($i = 1; $i < $len; $i++) {
        if ($q === '"' && $v[$i] === '\\') { $i++; continue; }
        if ($v[$i] === $q) return null;
    }
    return $q;
}

/**
 * Splits .env text into logical lines. A quoted value that runs over several
 * physical lines (a PEM key, say) is returned as one entry, joined with "\n".
 * An opening quote that is never closed is NOT allowed to swallow the rest of
 * the file: that line stands alone.
 *
 * Neither is a typo. Files written before multi-line support (and by hand) may hold
 * `KEY="unterminated` followed by ordinary lines; if a line inside the would-be value
 * looks like a fresh `UPPER_KEY=value` assignment, the quote is treated as a typo and
 * the lines are left alone, exactly as the old line-based parser saw them. Base64
 * padding (`QUJDRA==`) and empty assignments (`KEY=`) do not count as assignments.
 *
 * @return string[]
 */
function env_logical_lines(string $text): array
{
    $looksLikeAssignment = static fn(string $l): bool =>
        (bool) preg_match('/^\s*(?:export\s+)?[A-Z][A-Z0-9_]{2,}\s*=\s*[^=\s]/', $l);

    $raw = explode("\n", $text);
    $n   = count($raw);
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $line = $raw[$i];
        if (env_open_quote($line) !== null) {
            $joined = $line;
            for ($j = $i + 1; $j < $n; $j++) {
                if ($looksLikeAssignment($raw[$j])) {
                    break;                      // a new KEY=value inside the "value": it was a typo
                }
                $joined .= "\n" . $raw[$j];
                if (env_open_quote($joined) === null) {
                    $line = $joined;
                    $i    = $j;
                    break;
                }
            }
        }
        $out[] = $line;
    }
    return $out;
}

/**
 * Escapes backslashes and line breaks (`\`, CR, LF -> `\\`, `\r`, `\n`) so a
 * multi-line value fits in a single-line <input>, which would otherwise strip
 * the line breaks. Reverse with env_unescape_lines().
 */
function env_escape_lines(string $v): string
{
    return str_replace(['\\', "\r", "\n"], ['\\\\', '\\r', '\\n'], $v);
}

/**
 * Reverses env_escape_lines(). Only meaningful for fields the page flagged as
 * escaped — applying it to an ordinary value would turn a literal `\n` into a
 * line break.
 */
function env_unescape_lines(string $v): string
{
    return strtr($v, ['\\\\' => '\\', '\\n' => "\n", '\\r' => "\r"]);
}

/**
 * Blanks every value in a template (`KEY=secret` becomes `KEY=`), keeping
 * comments, blank lines, order and `export` prefixes. The stored template only
 * describes structure; values live encrypted in project_env_vars. Without this,
 * pasting or pulling a real .env would leave every secret in plaintext in
 * projects.env_template. Idempotent.
 */
function env_template_scrub(string $template): string
{
    $out = [];
    foreach (env_logical_lines($template) as $line) {
        $key = env_line_key($line);
        if ($key === null) {
            $out[] = $line;
            continue;
        }
        $out[] = (preg_match('/^\s*export\s+/i', $line) ? 'export ' : '') . $key . '=';
    }
    return implode("\n", $out);
}

/**
 * True if any assignment in the template carries a non-empty value.
 */
function env_template_has_values(string $template): bool
{
    foreach (env_parse_pairs($template) as $value) {
        if ($value !== '') return true;
    }
    return false;
}

/**
 * Reorders a template's assignments to follow $order (a list of key names).
 * Each assignment moves together with the comment/blank lines directly above it;
 * lines after the last assignment stay at the end. Keys not named in $order keep
 * their relative order after the named ones.
 *
 * @param string[] $order
 */
function env_template_reorder(string $template, array $order): string
{
    $blocks  = [];   // key => lines
    $pending = [];
    foreach (env_logical_lines($template) as $line) {
        $pending[] = $line;
        $key = env_line_key($line);
        if ($key !== null) {
            $blocks[$key] = array_merge($blocks[$key] ?? [], $pending);
            $pending      = [];
        }
    }
    $tail = $pending;

    $out  = [];
    $done = [];
    foreach ($order as $key) {
        if (isset($blocks[$key]) && !isset($done[$key])) {
            array_push($out, ...$blocks[$key]);
            $done[$key] = true;
        }
    }
    foreach ($blocks as $key => $lines) {
        if (!isset($done[$key])) {
            array_push($out, ...$lines);
        }
    }
    array_push($out, ...$tail);
    return implode("\n", $out);
}

/**
 * Compares two KEY => value maps without exposing any value.
 *
 * @param array<string,string> $managed  what the manager would write
 * @param array<string,string> $disk     what is in the file now
 * @return array{missing:string[], extra:string[], changed:string[]}
 *         missing = managed but not on disk, extra = on disk but not managed,
 *         changed = in both with different values
 */
function env_diff_pairs(array $managed, array $disk): array
{
    $changed = [];
    foreach ($managed as $key => $value) {
        if (array_key_exists($key, $disk) && $disk[$key] !== $value) {
            $changed[] = (string) $key;
        }
    }
    return [
        'missing' => array_map('strval', array_values(array_diff(array_keys($managed), array_keys($disk)))),
        'extra'   => array_map('strval', array_values(array_diff(array_keys($disk), array_keys($managed)))),
        'changed' => $changed,
    ];
}

/**
 * Encrypts an env bundle with a passphrase (PBKDF2-SHA256 -> AES-256-GCM) and
 * returns a self-describing JSON document. The passphrase, not SECRET_KEY, is
 * the key, so a bundle can be moved between installs.
 *
 * @param array<string,mixed> $payload
 * @throws RuntimeException if encryption is unavailable
 */
function env_bundle_encrypt(array $payload, string $passphrase): string
{
    $iter  = 200000;
    $salt  = random_bytes(16);
    $nonce = random_bytes(12);
    $key   = hash_pbkdf2('sha256', $passphrase, $salt, $iter, 32, true);
    $tag   = '';
    $ct    = openssl_encrypt(
        (string) json_encode($payload, JSON_UNESCAPED_SLASHES),
        'AES-256-GCM', $key, OPENSSL_RAW_DATA, $nonce, $tag, 'teraph-env-bundle-v1', 16
    );
    if ($ct === false) {
        throw new RuntimeException('AES-256-GCM is not available on this server.');
    }
    return (string) json_encode([
        'teraph_env_bundle' => 1,
        'kdf'               => 'pbkdf2-sha256',
        'iter'              => $iter,
        'salt'              => base64_encode($salt),
        'nonce'             => base64_encode($nonce),
        'tag'               => base64_encode($tag),
        'ct'                => base64_encode($ct),
    ], JSON_PRETTY_PRINT);
}

/**
 * Decrypts a document produced by env_bundle_encrypt().
 *
 * @return array<string,mixed>
 * @throws RuntimeException for a malformed file or a wrong passphrase
 */
function env_bundle_decrypt(string $document, string $passphrase): array
{
    $d = json_decode($document, true);
    if (!is_array($d) || ($d['teraph_env_bundle'] ?? null) !== 1
        || !isset($d['salt'], $d['nonce'], $d['tag'], $d['ct'], $d['iter'])) {
        throw new RuntimeException('This is not a TeraPH env bundle.');
    }
    $iter = (int) $d['iter'];
    if ($iter < 10000 || $iter > 1000000) {   // bound it: a crafted file must not pick the CPU cost
        throw new RuntimeException('Unsupported bundle parameters.');
    }
    $salt  = base64_decode((string) $d['salt'], true);
    $nonce = base64_decode((string) $d['nonce'], true);
    $tag   = base64_decode((string) $d['tag'], true);
    $ct    = base64_decode((string) $d['ct'], true);
    if ($salt === false || $nonce === false || $tag === false || $ct === false) {
        throw new RuntimeException('The bundle file is corrupt.');
    }
    $key   = hash_pbkdf2('sha256', $passphrase, $salt, $iter, 32, true);
    $plain = openssl_decrypt($ct, 'AES-256-GCM', $key, OPENSSL_RAW_DATA, $nonce, $tag, 'teraph-env-bundle-v1');
    if ($plain === false) {
        throw new RuntimeException('Wrong passphrase, or the bundle file is damaged.');
    }
    $payload = json_decode($plain, true);
    if (!is_array($payload) || !isset($payload['vars']) || !is_array($payload['vars'])) {
        throw new RuntimeException('The bundle contents are invalid.');
    }
    return $payload;
}
/**
 * Returns the key a template line assigns (ignoring a leading `export`), or
 * null for blank lines, comments and lines that aren't assignments.
 */
function env_line_key(string $line): ?string
{
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) return null;
    $line = (string) preg_replace('/^export\s+/i', '', $line);
    $eq   = strpos($line, '=');
    if ($eq === false) return null;
    $key = rtrim(substr($line, 0, $eq));
    return ($key !== '' && !preg_match('/[\s#=]/', $key)) ? $key : null;
}

/**
 * Removes every assignment line for $key from a template; comments and
 * unrelated lines are kept verbatim.
 */
function env_template_remove_key(string $template, string $key): string
{
    $kept = array_filter(
        env_logical_lines($template),
        fn(string $line) => env_line_key($line) !== $key
    );
    return implode("\n", $kept);
}

/**
 * Renames $old to $new on every assignment line in a template, preserving
 * indentation, any `export` prefix, and the rest of the line.
 */
function env_template_rename_key(string $template, string $old, string $new): string
{
    $pattern = '/^(\s*(?:export\s+)?)' . preg_quote($old, '/') . '(?=\s*=)/i';
    $lines   = env_logical_lines($template);
    foreach ($lines as &$line) {
        if (env_line_key($line) === $old) {
            $line = (string) preg_replace_callback($pattern, fn(array $m) => $m[1] . $new, $line);
        }
    }
    unset($line);
    return implode("\n", $lines);
}

/**
 * Formats a plain value for the right-hand side of KEY=value in a .env file.
 *
 * - Plain values are written bare.
 * - Values with whitespace, quotes or # are double-quoted with `"` and `\` escaped
 *   (understood the same way by phpdotenv and CodeIgniter 4's DotEnv).
 * - Values containing `${` are single-quoted when that is safe. Both parsers expand
 *   `${NAME}` references to defined variables, and phpdotenv treats single quotes as
 *   literal, so `pa${HOME}ss` survives intact.
 *
 * Limitations (see DECISIONS.md): a `${` value that ALSO contains a `'`, backslash or
 * newline can't be single-quoted (single quotes can't hold a `'`, and CI4 un-escapes
 * backslashes inside them), so it falls through to the rules above and phpdotenv may
 * still expand it. And CI4's DotEnv expands `${NAME}` inside any quotes with no
 * escape, so a `${NAME}` naming a variable defined there cannot be written safely.
 */
function env_quote_value(string $val): string
{
    if ($val === '') return '';
    if (str_contains($val, '${') && !preg_match('/[\'\\\\\r\n]/', $val)) {
        return "'" . $val . "'";
    }
    if (preg_match('/[\s"\'#]/', $val)) {
        return '"' . addcslashes($val, '"\\') . '"';
    }
    return $val;
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
    $lines  = env_logical_lines($template);
    $output = [];
    foreach ($lines as $line) {
        $rtrimmed = rtrim($line);
        $trimmed  = ltrim($rtrimmed);       // fully trimmed for logic checks
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            $output[] = $rtrimmed;          // preserve blank/comment lines verbatim
            continue;
        }
        // Detect and strip optional 'export ' prefix; re-apply it in output
        $hasExport = (bool) preg_match('/^export\s+/i', $trimmed);
        $bare      = $hasExport ? (string) preg_replace('/^export\s+/i', '', $trimmed) : $trimmed;
        $eq        = strpos($bare, '=');
        if ($eq === false) {
            $output[] = $rtrimmed;
            continue;
        }
        $key = rtrim(substr($bare, 0, $eq));
        if (isset($values[$key])) {
            $output[] = ($hasExport ? 'export ' : '') . $key . '=' . env_quote_value($values[$key]);
        } else {
            $output[] = $rtrimmed;
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
