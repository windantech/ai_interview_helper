<?php

declare(strict_types=1);

/*
 * Application bootstrap — included at the top of every page and API endpoint.
 * Works with or without Composer (PSR-4 fallback autoloader for App\ namespace).
 */

use App\Core\Config;
use App\Core\Env;
use App\Core\HttpException;
use App\Core\Logger;
use App\Core\Session;

define('APP_ROOT', dirname(__DIR__));

if (PHP_VERSION_ID < 80200) {
    http_response_code(500);
    exit('AI Interview Copilot requires PHP 8.2 or newer.');
}

if (is_file(APP_ROOT . '/vendor/autoload.php')) {
    require APP_ROOT . '/vendor/autoload.php';
}
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $file = APP_ROOT . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require_once APP_ROOT . '/app/helpers.php';

Env::load(APP_ROOT . '/.env');
Config::init(APP_ROOT . '/config');

date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

// Errors: never display in production; always log.
error_reporting(E_ALL);
ini_set('display_errors', is_debug() ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', (string) config('app.storage_path') . '/logs/php-errors.log');

$isApi = defined('API_REQUEST') && API_REQUEST === true;
$isCli = PHP_SAPI === 'cli';

if (!$isCli) {
    // Security headers
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: microphone=(self), camera=(), geolocation=()');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; "
        . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com data:; "
        . "img-src 'self' data: blob:; media-src 'self' blob:; "
        . "connect-src 'self' https://api.openai.com; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
    Session::start();
}

set_exception_handler(static function (Throwable $e) use ($isApi, $isCli): void {
    $status = $e instanceof HttpException ? $e->status() : 500;
    if (!$e instanceof HttpException) {
        Logger::error('Unhandled exception', ['error' => $e->getMessage(), 'file' => $e->getFile() . ':' . $e->getLine()]);
    }
    $message = $e instanceof HttpException ? $e->getMessage()
        : (is_debug() ? $e->getMessage() : 'Something went wrong. Please try again.');

    if ($isCli) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
    if ($isApi) {
        \App\Core\Response::error($message, $status);
    }
    http_response_code($status);
    \App\Core\View::render('errors/error', ['status' => $status, 'message' => $message]);
    exit;
});
