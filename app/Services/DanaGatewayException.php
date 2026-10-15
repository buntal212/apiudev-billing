<?php

namespace App\Services;

use RuntimeException;

class DanaGatewayException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable)
    {
        parent::__construct($message);
    }
}
