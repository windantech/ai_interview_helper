<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Minimal .env loader (no external dependency).
 * Supports KEY=value, KEY="quoted value", KEY='single', comments (#) and blank lines.
 */
final class Env
{
    /** @var array<string,string> */
    private static array $values = [];

    public static function load(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            return;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            if (str_starts_with($key, 'export ')) {
                $key = trim(substr($key, 7));
            }
            if (!preg_match('/^[A-Z0-9_]+$/i', $key)) {
                continue;
            }
            self::$values[$key] = self::parseValue(trim($value));
        }
    }

    public static function parseValue(string $value): string
    {
        if ($value === '') {
            return '';
        }
        $first = $value[0];
        if (($first === '"' || $first === "'") && ($end = strpos($value, $first, 1)) !== false) {
            $inner = substr($value, 1, $end - 1);
            return $first === '"' ? str_replace(['\\n', '\\"'], ["\n", '"'], $inner) : $inner;
        }
        // Strip inline comments for unquoted values
        $hash = strpos($value, ' #');
        if ($hash !== false) {
            $value = substr($value, 0, $hash);
        }
        return trim($value);
    }

    /** Real environment variables take precedence over .env values (standard dotenv behaviour). */
    public static function get(string $key, mixed $default = null): mixed
    {
        $server = getenv($key);
        if ($server !== false) {
            return $server;
        }
        if (array_key_exists($key, self::$values)) {
            return self::$values[$key];
        }
        return $default;
    }
}
