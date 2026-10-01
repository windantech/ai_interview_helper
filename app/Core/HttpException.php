<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Exception carrying an HTTP status and a message that is SAFE to show to users.
 */
class HttpException extends \RuntimeException
{
    /** @param array<string,mixed> $extra */
    public function __construct(
        private readonly int $status,
        string $message,
        private readonly array $extra = []
    ) {
        parent::__construct($message, $status);
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string,mixed> */
    public function extra(): array
    {
        return $this->extra;
    }
}
