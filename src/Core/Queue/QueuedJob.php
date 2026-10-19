<?php

declare(strict_types=1);

namespace App\Core\Queue;

final class QueuedJob
{
    /** @param array<string,mixed> $payload */
    public function __construct(
        public readonly int $id,
        public readonly string $class,
        public readonly array $payload,
        public readonly int $attempts,
        public readonly int $maxAttempts,
    ) {
    }
}
