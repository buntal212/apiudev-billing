<?php

namespace App\Exceptions;

use RuntimeException;

class IntegrationConflictException extends RuntimeException
{
    public function __construct(
        public readonly string $safeCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
