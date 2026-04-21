<?php
// =============================================================================
// server.php — PHP Built-in Dev Server Router
// =============================================================================
// This file is passed to `php -S` as the router script.
// It ensures .php files are executed and static assets are served directly.
//
// Usage (handled by `php tera serve`):
//   php -S localhost:8000 -t public/ server.php
// =============================================================================

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// Serve static assets (CSS, JS, images, fonts) directly
$staticExtensions = ['css', 'js', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'ico',
                     'woff', 'woff2', 'ttf', 'eot', 'webp', 'map'];

$ext = strtolower(pathinfo($uri, PATHINFO_EXTENSION));

if (in_array($ext, $staticExtensions, true)) {
    // Let the built-in server handle static files natively
    return false;
}

// Map root URI to index.php
if ($uri === '/' || $uri === '') {
    require __DIR__ . '/public/index.php';
    return true;
}

// Check if the requested .php file exists in /public
$file = __DIR__ . '/public' . $uri;

if (file_exists($file) && !is_dir($file)) {
    // Let built-in server serve it normally
    return false;
}

// Append .php if missing
if (file_exists($file . '.php')) {
    require $file . '.php';
    return true;
}

// 404 fallback
http_response_code(404);
echo "<h1>404 Not Found</h1><p><code>{$uri}</code> was not found.</p>";
