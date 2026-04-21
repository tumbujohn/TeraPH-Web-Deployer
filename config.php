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
define('APP_PASSWORD_HASH', '$2y$12$cq0zjsVKw9LPrOxK2AG9ju293WHn51U9H7SrLQgqKX1q4Psbx7ygq');

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

// ---- GitHub ----
define('GITHUB_PAT', '');

// ---- Webhook (Phase 2) ----
define('WEBHOOK_SECRET', '');

// ---- Development ----
// Set to true on local dev to bypass SSL certificate verification.
// NEVER set this to true in production — it disables HTTPS security.
define('DEV_MODE',       true);
define('CURL_SSL_VERIFY', !DEV_MODE);
