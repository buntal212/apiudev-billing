<?php

namespace App\Services;

use RuntimeException;

final class SettlementRejectedException extends RuntimeException
{
    public function __construct(public readonly string $safeCode, string $message)
    {
        parent::__construct($message);
    }
}
