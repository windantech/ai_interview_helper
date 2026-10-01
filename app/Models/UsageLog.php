<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Logger;

/**
 * Records OpenAI usage (tokens + estimated cost) per user/action.
 */
final class UsageLog
{
    public static function record(?int $userId, string $action, string $model, int $inputTokens, int $outputTokens): void
    {
        try {
            Database::execute(
                'INSERT INTO usage_logs (user_id, action, model, input_tokens, output_tokens, estimated_cost) VALUES (?, ?, ?, ?, ?, ?)',
                [$userId, $action, mb_substr($model, 0, 80), max(0, $inputTokens), max(0, $outputTokens), self::estimate($model, $inputTokens, $outputTokens)]
            );
        } catch (\Throwable $e) {
            Logger::warning('Usage log write failed', ['error' => $e->getMessage()]);
        }
    }

    public static function estimate(string $model, int $in, int $out): float
    {
        $pricing = (array) config('openai.pricing', []);
        $p = $pricing[$model] ?? null;
        if (!$p) {
            return 0.0;
        }
        return round(($in / 1_000_000) * $p['input'] + ($out / 1_000_000) * $p['output'], 6);
    }

    /** @return array<string,mixed> */
    public static function summary(int $userId): array
    {
        return Database::fetch(
            'SELECT COUNT(*) AS calls, COALESCE(SUM(input_tokens),0) AS input_tokens, COALESCE(SUM(output_tokens),0) AS output_tokens,
                    COALESCE(SUM(estimated_cost),0) AS cost
             FROM usage_logs WHERE user_id = ? AND created_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY',
            [$userId]
        ) ?? ['calls' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'cost' => 0];
    }
}
