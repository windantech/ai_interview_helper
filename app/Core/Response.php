<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Standard JSON API responses:
 *   success: {"success": true,  "data": {...}}
 *   failure: {"success": false, "message": "..."}
 */
final class Response
{
    public static function json(array $payload, int $status = 200): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, max-age=0');
            header('X-Content-Type-Options: nosniff');
        }
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    public static function success(array $data = [], int $status = 200): never
    {
        self::json(['success' => true, 'data' => (object) $data], $status);
    }

    public static function error(string $message, int $status = 400, array $extra = []): never
    {
        self::json(array_merge(['success' => false, 'message' => $message], $extra), $status);
    }
}
