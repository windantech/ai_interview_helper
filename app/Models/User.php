<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class User
{
    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::fetch(
            'SELECT id, name, email, status, last_login_at, created_at, updated_at FROM users WHERE id = ?',
            [$id]
        );
    }

    /** Includes password_hash — only use for authentication. @return array<string,mixed>|null */
    public static function findByEmailWithHash(string $email): ?array
    {
        return Database::fetch('SELECT * FROM users WHERE email = ?', [mb_strtolower(trim($email))]);
    }

    public static function emailExists(string $email, ?int $exceptId = null): bool
    {
        if ($exceptId !== null) {
            return (bool) Database::value('SELECT 1 FROM users WHERE email = ? AND id <> ?', [mb_strtolower($email), $exceptId]);
        }
        return (bool) Database::value('SELECT 1 FROM users WHERE email = ?', [mb_strtolower($email)]);
    }

    public static function create(string $name, string $email, string $password): int
    {
        return Database::transaction(function () use ($name, $email, $password) {
            $id = Database::insert(
                'INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)',
                [$name, mb_strtolower($email), password_hash($password, PASSWORD_DEFAULT)]
            );
            Database::execute('INSERT INTO user_settings (user_id) VALUES (?)', [$id]);
            return $id;
        });
    }

    public static function verifyPassword(int $id, string $password): bool
    {
        $hash = Database::value('SELECT password_hash FROM users WHERE id = ?', [$id]);
        return is_string($hash) && password_verify($password, $hash);
    }

    /** Re-hash the password if the algorithm/cost changed. */
    public static function rehashIfNeeded(int $id, string $password, string $hash): void
    {
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            Database::execute('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $id]);
        }
    }

    public static function updatePassword(int $id, string $password): void
    {
        Database::execute('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $id]);
    }

    public static function updateProfile(int $id, string $name, string $email): void
    {
        Database::execute('UPDATE users SET name = ?, email = ? WHERE id = ?', [$name, mb_strtolower($email), $id]);
    }

    public static function touchLogin(int $id): void
    {
        Database::execute('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = ?', [$id]);
    }

    /** Deletes the user row; FK cascades remove CV rows, jobs, sessions, questions, logs, resets. */
    public static function delete(int $id): void
    {
        Database::execute('DELETE FROM users WHERE id = ?', [$id]);
    }
}
