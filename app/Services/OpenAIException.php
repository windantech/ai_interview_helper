<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;

/**
 * OpenAI failure with a user-safe message. $kind lets callers react (e.g. "timeout", "quota", "auth").
 */
final class OpenAIException extends HttpException
{
    public function __construct(int $status, string $message, private readonly string $kind = 'error', private readonly int $upstreamStatus = 0)
    {
        parent::__construct($status, $message, ['error_kind' => $kind]);
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function upstreamStatus(): int
    {
        return $this->upstreamStatus;
    }
}
