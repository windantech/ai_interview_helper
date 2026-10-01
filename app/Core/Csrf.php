<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Synchroniser-token CSRF protection for forms (_csrf field) and API calls (X-CSRF-Token header).
 */
final class Csrf
{
    private const KEY = '_csrf_token';

    public static function token(): string
    {
        if (empty($_SESSION[self::KEY]) || !is_string($_SESSION[self::KEY])) {
            $_SESSION[self::KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::KEY];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(self::token()) . '">';
    }

    public static function rotate(): void
    {
        $_SESSION[self::KEY] = bin2hex(random_bytes(32));
    }

    public static function valid(?string $token): bool
    {
        $expected = $_SESSION[self::KEY] ?? '';
        return is_string($token) && $token !== '' && is_string($expected) && $expected !== ''
            && hash_equals($expected, $token);
    }

    /** Validate the token from POST body or X-CSRF-Token header; throws 419 on failure. */
    public static function verify(): void
    {
        $token = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        if (!self::valid(is_string($token) ? $token : null)) {
            Logger::security('CSRF validation failed', ['path' => $_SERVER['REQUEST_URI'] ?? '']);
            throw new HttpException(419, 'Your session has expired. Please refresh the page and try again.');
        }
    }
}
