<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Secure PHP session handling + flash messages.
 */
final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $secure = is_https();
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.gc_maxlifetime', (string) max(1800, (int) config('app.session_timeout_minutes', 120) * 60));
        session_name((string) config('app.session_name', 'icp_session'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => self::cookiePath(),
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    private static function cookiePath(): string
    {
        $path = parse_url((string) config('app.url', ''), PHP_URL_PATH);
        return is_string($path) && $path !== '' ? rtrim($path, '/') . '/' : '/';
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function destroy(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $_SESSION = [];
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'] ?? 'Lax',
        ]);
        session_destroy();
    }

    /** Flash message for the next request. Types: success|error|info|warning */
    public static function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    /** @return list<array{type:string,message:string}> */
    public static function pullFlashes(): array
    {
        $f = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $f;
    }

    /** Preserve old form input across a redirect. */
    public static function flashInput(array $input): void
    {
        unset($input['password'], $input['password_confirmation'], $input['_csrf'], $input['current_password']);
        $_SESSION['_old'] = $input;
    }

    public static function old(string $key, string $default = ''): string
    {
        $v = $_SESSION['_old'][$key] ?? $default;
        return is_scalar($v) ? (string) $v : $default;
    }

    public static function clearOld(): void
    {
        unset($_SESSION['_old']);
    }
}
