<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Fixed-window rate limiter backed by the `rate_limits` MySQL table.
 * Keys are hashed so raw emails/IPs are not stored.
 */
final class RateLimiter
{
    /**
     * Records a hit and returns true when the action is allowed.
     */
    public static function attempt(string $action, string $identity, ?int $max = null, ?int $window = null): bool
    {
        [$defMax, $defWindow] = config('app.rate_limits.' . $action, [60, 60]);
        $max ??= (int) $defMax;
        $window ??= (int) $defWindow;
        $key = hash('sha256', $action . '|' . $identity);
        $now = time();

        try {
            $row = Database::fetch('SELECT hits, window_start FROM rate_limits WHERE rate_key = ?', [$key]);
            if ($row === null || ((int) $row['window_start'] + $window) <= $now) {
                Database::execute(
                    'INSERT INTO rate_limits (rate_key, action, hits, window_start) VALUES (?, ?, 1, ?)
                     ON DUPLICATE KEY UPDATE hits = 1, window_start = VALUES(window_start)',
                    [$key, $action, $now]
                );
                self::maybeCleanup($now);
                return true;
            }
            if ((int) $row['hits'] >= $max) {
                return false;
            }
            Database::execute('UPDATE rate_limits SET hits = hits + 1 WHERE rate_key = ?', [$key]);
            return true;
        } catch (HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Fail open on limiter storage problems, but record them.
            Logger::error('Rate limiter failure', ['action' => $action, 'error' => $e->getMessage()]);
            return true;
        }
    }

    /** Throw a 429 if the limit is exceeded. */
    public static function enforce(string $action, string $identity, ?int $max = null, ?int $window = null): void
    {
        if (!self::attempt($action, $identity, $max, $window)) {
            Logger::security('Rate limit exceeded', ['action' => $action]);
            throw new HttpException(429, 'Too many requests. Please wait a moment and try again.');
        }
    }

    public static function clear(string $action, string $identity): void
    {
        try {
            Database::execute('DELETE FROM rate_limits WHERE rate_key = ?', [hash('sha256', $action . '|' . $identity)]);
        } catch (\Throwable) {
            // ignore
        }
    }

    private static function maybeCleanup(int $now): void
    {
        if (random_int(1, 100) === 1) {
            Database::execute('DELETE FROM rate_limits WHERE window_start < ?', [$now - 86400]);
        }
    }
}
