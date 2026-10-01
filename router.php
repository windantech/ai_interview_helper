<?php

declare(strict_types=1);

/*
 * Router for PHP's built-in development server:
 *     php -S localhost:8000 router.php
 *
 * The built-in server ignores .htaccess, so this script enforces the same rules:
 * private folders (/app, /config, /storage, ...) and dotfiles are never served.
 */

$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
$path = '/' . ltrim(str_replace('\\', '/', $path), '/');

$blocked = '#^/(app|config|database|storage|views|tests|vendor)(/|$)|/\.|^/(composer\.(json|lock)|README\.md|router\.php)$|\.(env|sql|log|ini|md)$#i';
if (preg_match($blocked, $path) || str_contains($path, '..')) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo '403 Forbidden';
    return true;
}

$file = __DIR__ . $path;
if ($path === '/' || is_dir($file)) {
    $file = rtrim($file, '/') . '/index.php';
}

if (is_file($file)) {
    if (str_ends_with($file, '.php')) {
        $_SERVER['SCRIPT_NAME'] = substr($file, strlen(__DIR__));
        chdir(dirname($file));
        require $file;
        return true;
    }
    return false; // let the built-in server serve static assets
}

http_response_code(404);
require __DIR__ . '/app/bootstrap.php';
App\Core\View::render('errors/error', ['status' => 404, 'message' => 'The page you are looking for does not exist.']);
return true;
