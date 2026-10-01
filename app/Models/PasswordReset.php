<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Password reset tokens. Only SHA-256 hashes are stored; tokens expire after 60 minutes.
 */
final class PasswordReset
{
    public const TTL_MINUTES = 60;

    /** Create a token for the user and return the plain token (sent by email only). */
    public static function create(int $userId): string
    {
        $token = bin2hex(random_bytes(32));
        Database::execute('UPDATE password_resets SET used_at = UTC_TIMESTAMP() WHERE user_id = ? AND used_at IS NULL', [$userId]);
        Database::insert(
            'INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, UTC_TIMESTAMP() + INTERVAL ' . self::TTL_MINUTES . ' MINUTE)',
            [$userId, hash('sha256', $token)]
        );
        return $token;
    }

    /** @return array<string,mixed>|null valid (unused, unexpired) reset row */
    public static function findValid(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        return Database::fetch(
            'SELECT * FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()',
            [hash('sha256', $token)]
        );
    }

    public static function markUsed(int $id): void
    {
        Database::execute('UPDATE password_resets SET used_at = UTC_TIMESTAMP() WHERE id = ?', [$id]);
    }

    public static function purgeExpired(): void
    {
        Database::execute('DELETE FROM password_resets WHERE expires_at < UTC_TIMESTAMP() - INTERVAL 7 DAY');
    }
}
