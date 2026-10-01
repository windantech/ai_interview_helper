<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Per-user interview preferences (user_settings table).
 */
final class Settings
{
    public const DEFAULTS = [
        'default_answer_mode'    => 'auto',
        'response_detail'        => 'short',
        'default_interview_type' => 'general',
        'auto_detect_question'   => 1,
        'show_transcript'        => 1,
        'save_history'           => 1,
        'transcription_mode'     => 'auto',
        'active_job_id'          => null,
        'mic_consent_at'         => null,
    ];

    /** @return array<string,mixed> */
    public static function forUser(int $userId): array
    {
        $row = Database::fetch('SELECT * FROM user_settings WHERE user_id = ?', [$userId]);
        if ($row === null) {
            Database::execute('INSERT IGNORE INTO user_settings (user_id, response_detail) VALUES (?, ?)', [$userId, config('app.default_answer_length', 'short')]);
            $row = ['user_id' => $userId] + self::DEFAULTS;
        }
        foreach (['auto_detect_question', 'show_transcript', 'save_history'] as $b) {
            $row[$b] = (bool) $row[$b];
        }
        $row['active_job_id'] = $row['active_job_id'] !== null ? (int) $row['active_job_id'] : null;
        return $row;
    }

    /** @param array<string,mixed> $values */
    public static function update(int $userId, array $values): void
    {
        $allowed = array_keys(self::DEFAULTS);
        $sets = [];
        $params = [];
        foreach ($values as $k => $v) {
            if (!in_array($k, $allowed, true)) {
                continue;
            }
            $sets[] = "`$k` = ?";
            $params[] = is_bool($v) ? (int) $v : $v;
        }
        if (!$sets) {
            return;
        }
        self::forUser($userId); // ensure row exists
        $params[] = $userId;
        Database::execute('UPDATE user_settings SET ' . implode(', ', $sets) . ' WHERE user_id = ?', $params);
    }
}
