<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Helpers shared by every /api/*.php endpoint: method checks, CSRF, auth, JSON body, error handling.
 */
final class Api
{
    /**
     * Run an endpoint handler with uniform error handling.
     * @param callable():never|callable():void $handler
     */
    public static function handle(callable $handler): never
    {
        try {
            $handler();
            Response::error('No response produced.', 500);
        } catch (HttpException $e) {
            Response::error($e->getMessage(), $e->status(), $e->extra());
        } catch (\PDOException $e) {
            Logger::error('Database error', ['error' => $e->getMessage(), 'path' => $_SERVER['REQUEST_URI'] ?? '']);
            Response::error('A database error occurred. Please try again.', 500);
        } catch (\Throwable $e) {
            Logger::error('Unhandled API error', [
                'error' => $e->getMessage(),
                'file'  => $e->getFile() . ':' . $e->getLine(),
                'path'  => $_SERVER['REQUEST_URI'] ?? '',
            ]);
            $msg = is_debug() ? $e->getMessage() : 'Something went wrong. Please try again.';
            Response::error($msg, 500);
        }
    }

    /** @param list<string> $methods */
    public static function allow(array $methods): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (!in_array($method, $methods, true)) {
            header('Allow: ' . implode(', ', $methods));
            throw new HttpException(405, 'Method not allowed.');
        }
    }

    /**
     * POST endpoint guard: method + auth + CSRF + general rate limit.
     * @return array<string,mixed> authenticated user
     */
    public static function postGuard(): array
    {
        self::allow(['POST']);
        $user = Auth::requireApiUser();
        Csrf::verify();
        RateLimiter::enforce('api_general', 'u' . $user['id']);
        return $user;
    }

    /** @return array<string,mixed> authenticated user */
    public static function getGuard(): array
    {
        self::allow(['GET']);
        return Auth::requireApiUser();
    }

    /** Decode a JSON request body (falls back to $_POST for form submissions). @return array<string,mixed> */
    public static function input(): array
    {
        $type = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($type, 'application/json')) {
            $raw = file_get_contents('php://input') ?: '';
            if (strlen($raw) > 1_000_000) {
                throw new HttpException(413, 'Request is too large.');
            }
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                throw new HttpException(400, 'Invalid JSON request body.');
            }
            return $data;
        }
        return $_POST;
    }
}
