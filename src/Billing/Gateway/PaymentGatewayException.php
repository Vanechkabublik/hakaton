<?php

declare(strict_types=1);

namespace App\Billing\Gateway;

final class PaymentGatewayException extends \RuntimeException
{
    public function __construct(string $message, public readonly ?int $httpStatus = null, public readonly ?string $errorCode = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
