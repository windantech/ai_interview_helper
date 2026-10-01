<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Lazy loader for /config/*.php arrays with dot-notation access: config('openai.answer_model').
 */
final class Config
{
    /** @var array<string,array<string,mixed>> */
    private static array $items = [];
    private static string $path = '';

    public static function init(string $path): void
    {
        self::$path = rtrim($path, '/');
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $file = array_shift($segments);
        if (!isset(self::$items[$file])) {
            $filePath = self::$path . '/' . basename($file) . '.php';
            self::$items[$file] = is_file($filePath) ? (array) require $filePath : [];
        }
        $value = self::$items[$file];
        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    /** Override a value at runtime (used by tests). */
    public static function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $file = array_shift($segments);
        self::get($file . '.__probe');
        $ref = &self::$items[$file];
        foreach ($segments as $segment) {
            if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
                $ref[$segment] = $ref[$segment] ?? [];
            }
            $ref = &$ref[$segment];
        }
        $ref = $value;
    }
}
