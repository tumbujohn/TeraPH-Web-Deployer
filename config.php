<?php
// =============================================================================
// TeraPH Web Deployer — Active Configuration
// =============================================================================
// This file is gitignored. It is NOT committed to version control.
// =============================================================================

// ---- Application ----
define('APP_NAME', 'TeraPH Deployer');
define('APP_URL', ''); // Set to the URL where /public/ is served from

// ---- Authentication ----
define('APP_USERNAME', 'admin');
// Default password is: deployer123 — CHANGE THIS IN PRODUCTION
// Generate with: php -r "echo password_hash('yourpassword', PASSWORD_BCRYPT);"
define('APP_PASSWORD_HASH', '$2y$12$ANia4TXy/sA6HAl4.vL7fek6JCfpcR18bvqxRyUw0yzNhmQtilEKG');

// ---- Session ----
define('SESSION_NAME', 'tera_deployer_session');
define('SESSION_TIMEOUT', 3600);

// ---- Database ----
define('DB_DRIVER', 'sqlite');
define('DB_PATH',  __DIR__ . '/storage/deployer.db');
define('DB_HOST',  'localhost');
define('DB_PORT',  '3306');
define('DB_NAME',  'deployer');
define('DB_USER',  'root');
define('DB_PASS',  '');

// ---- Storage ----
define('STORAGE_PATH', __DIR__ . '/storage');
define('BACKUP_PATH',  __DIR__ . '/storage/backups');
define('TMP_PATH',     __DIR__ . '/storage/tmp');
define('LOG_PATH',     __DIR__ . '/storage/logs');

// ---- Deployment ----
define('MAX_BACKUPS_PER_PROJECT', 10);
define('DOWNLOAD_TIMEOUT',        300);
define('LOCK_TIMEOUT',            1200);
define('HOOK_TIMEOUT',            300);  // Max seconds for a single deploy hook command

// ---- Deploy Strategy ----
// 'auto'    → detect symlink capability on first deploy and cache the result
// 'symlink' → force symlink mode (P0.1 — not yet fully implemented)
// 'copy'    → always use enhanced copy-in-place (current behaviour)
define('DEPLOY_STRATEGY', 'auto');

// ---- Deployer Root ----
// Absolute path to the deployer installation root. Used by the self-protection
// guard to prevent the deployer from deleting itself during a deployment.
define('DEPLOYER_ROOT', dirname(__DIR__));

// ---- Terminal ----
define('TERMINAL_ENABLED', true);   // Set to false to disable the web terminal site-wide
define('TERMINAL_TIMEOUT', 600);    // Max seconds a single terminal command may run

// ---- GitHub ----
define('GITHUB_PAT', '');

// ---- Webhook (Phase 2) ----
define('WEBHOOK_SECRET', '');

// ---- Development ----
// Set to true on local dev to bypass SSL certificate verification.
// NEVER set this to true in production — it disables HTTPS security.
define('DEV_MODE',       true);
define('CURL_SSL_VERIFY', !DEV_MODE);
