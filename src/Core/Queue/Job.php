<?php

declare(strict_types=1);

namespace App\Core\Queue;

/** Фоновая задача. Класс разрешается через DI-контейнер, поэтому может принимать сервисы в конструкторе. */
interface Job
{
    /** @param array<string,mixed> $payload */
    public function handle(array $payload): void;
}
