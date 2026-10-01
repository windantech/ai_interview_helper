<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\User;

/**
 * Session-based authentication + route protection middleware.
 */
final class Auth
{
    private static ?array $user = null;

    public static function login(array $user): void
    {
        Session::regenerate();
        Csrf::rotate();
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['last_activity'] = time();
        $_SESSION['ua_hash'] = self::uaHash();
        self::$user = $user;
    }

    public static function logout(): void
    {
        self::$user = null;
        Session::destroy();
    }

    private static function uaHash(): string
    {
        return hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    }

    public static function id(): ?int
    {
        return self::user()['id'] ?? null;
    }

    /** @return array<string,mixed>|null */
    public static function user(): ?array
    {
        if (self::$user !== null) {
            return self::$user;
        }
        $id = $_SESSION['user_id'] ?? null;
        if (!is_int($id)) {
            return null;
        }

        $timeout = (int) config('app.session_timeout_minutes', 120) * 60;
        $last = (int) ($_SESSION['last_activity'] ?? 0);
        if ($timeout > 0 && $last > 0 && (time() - $last) > $timeout) {
            self::logout();
            Session::start();
            Session::flash('info', 'You were signed out after a period of inactivity.');
            return null;
        }
        if (($_SESSION['ua_hash'] ?? '') !== self::uaHash()) {
            Logger::security('Session user-agent mismatch; session terminated', ['user_id' => $id]);
            self::logout();
            return null;
        }

        $user = User::find($id);
        if ($user === null || $user['status'] !== 'active') {
            self::logout();
            return null;
        }
        $_SESSION['last_activity'] = time();
        self::$user = $user;
        return $user;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    /** Page middleware: redirect guests to login. @return array<string,mixed> */
    public static function requireUser(): array
    {
        $user = self::user();
        if ($user === null) {
            $_SESSION['intended'] = $_SERVER['REQUEST_URI'] ?? null;
            redirect('login.php');
        }
        return $user;
    }

    /** API middleware: JSON 401 for guests. @return array<string,mixed> */
    public static function requireApiUser(): array
    {
        $user = self::user();
        if ($user === null) {
            throw new HttpException(401, 'Your session has ended. Please sign in again.');
        }
        return $user;
    }

    /** Redirect signed-in users away from guest pages. */
    public static function redirectIfAuthenticated(): void
    {
        if (self::check()) {
            redirect('dashboard.php');
        }
    }
}
