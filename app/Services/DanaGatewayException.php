<?php

namespace App\Services;

use RuntimeException;

class DanaGatewayException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $retryable,
        public readonly ?int $statusCode = null,
        public readonly ?int $retryAfterSeconds = null,
        public readonly string $errorCode = 'gateway_unavailable',
    ) {
        parent::__construct($message);
    }
}
