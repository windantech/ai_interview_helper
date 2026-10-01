<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class CV
{
    /** @return array<string,mixed>|null */
    public static function forUser(int $userId): ?array
    {
        return Database::fetch('SELECT * FROM user_cvs WHERE user_id = ?', [$userId]);
    }

    /** Lightweight metadata (no CV text). @return array<string,mixed>|null */
    public static function metaForUser(int $userId): ?array
    {
        return Database::fetch(
            'SELECT id, user_id, original_filename, mime_type, file_size, status, extraction_error, created_at, updated_at,
                    (cv_profile_json IS NOT NULL) AS has_profile
             FROM user_cvs WHERE user_id = ?',
            [$userId]
        );
    }

    /** Insert or replace the user's single CV record. */
    public static function upsert(int $userId, string $original, string $stored, string $mime, int $size): int
    {
        Database::execute(
            'INSERT INTO user_cvs (user_id, original_filename, stored_filename, mime_type, file_size, status)
             VALUES (?, ?, ?, ?, ?, \'processing\')
             ON DUPLICATE KEY UPDATE original_filename = VALUES(original_filename), stored_filename = VALUES(stored_filename),
                mime_type = VALUES(mime_type), file_size = VALUES(file_size), status = \'processing\',
                cv_text = NULL, cv_profile_json = NULL, openai_file_id = NULL, extraction_error = NULL,
                created_at = UTC_TIMESTAMP()',
            [$userId, $original, $stored, $mime, $size]
        );
        return (int) Database::value('SELECT id FROM user_cvs WHERE user_id = ?', [$userId]);
    }

    public static function saveExtraction(int $userId, ?string $text, ?array $profile, ?string $openaiFileId): void
    {
        Database::execute(
            'UPDATE user_cvs SET cv_text = ?, cv_profile_json = ?, openai_file_id = ?, status = \'ready\', extraction_error = NULL WHERE user_id = ?',
            [
                $text,
                $profile !== null ? json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                $openaiFileId,
                $userId,
            ]
        );
    }

    public static function markFailed(int $userId, string $reason, ?string $text = null): void
    {
        Database::execute(
            'UPDATE user_cvs SET status = \'failed\', extraction_error = ?, cv_text = COALESCE(?, cv_text) WHERE user_id = ?',
            [mb_substr($reason, 0, 250), $text, $userId]
        );
    }

    public static function delete(int $userId): void
    {
        Database::execute('DELETE FROM user_cvs WHERE user_id = ?', [$userId]);
    }

    /** @return array<string,mixed>|null decoded profile */
    public static function profile(?array $cv): ?array
    {
        if (!$cv || empty($cv['cv_profile_json'])) {
            return null;
        }
        $p = json_decode((string) $cv['cv_profile_json'], true);
        return is_array($p) ? $p : null;
    }
}
