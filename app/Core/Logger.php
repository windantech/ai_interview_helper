<?php

declare(strict_types=1);

namespace App\Core;

/**
 * File logger writing to storage/logs/app-YYYY-MM-DD.log.
 * Context values are redacted for anything that looks like a secret.
 */
final class Logger
{
    private const SENSITIVE_KEYS = ['password', 'pass', 'token', 'api_key', 'apikey', 'authorization', 'secret', 'cv_text', 'value', 'csrf'];

    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('WARNING', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }

    public static function security(string $message, array $context = []): void
    {
        $context['ip'] = $context['ip'] ?? client_ip();
        self::write('SECURITY', $message, $context);
    }

    /** @return array<string,mixed> */
    public static function redact(array $context): array
    {
        foreach ($context as $key => $value) {
            $lower = strtolower((string) $key);
            foreach (self::SENSITIVE_KEYS as $s) {
                if (str_contains($lower, $s)) {
                    $context[$key] = '[redacted]';
                    continue 2;
                }
            }
            if (is_array($value)) {
                $context[$key] = self::redact($value);
            } elseif (is_string($value)) {
                $value = preg_replace('/sk-[A-Za-z0-9_\-]{8,}/', 'sk-[redacted]', $value) ?? $value;
                $value = preg_replace('/ek_[A-Za-z0-9_\-]{8,}/', 'ek_[redacted]', $value) ?? $value;
                $value = preg_replace('/Bearer\s+[A-Za-z0-9_\-\.]+/i', 'Bearer [redacted]', $value) ?? $value;
                $context[$key] = mb_substr($value, 0, 1000);
            }
        }
        return $context;
    }

    private static function write(string $level, string $message, array $context): void
    {
        $dir = (string) config('app.storage_path', dirname(__DIR__, 2) . '/storage') . '/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $line = sprintf(
            "[%s] %s: %s %s\n",
            gmdate('Y-m-d H:i:s'),
            $level,
            $message,
            $context ? json_encode(self::redact($context), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : ''
        );
        @file_put_contents($dir . '/app-' . gmdate('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }
}
