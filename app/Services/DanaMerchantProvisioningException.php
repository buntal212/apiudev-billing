<?php

namespace App\Services;

use RuntimeException;

class DanaMerchantProvisioningException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly bool $retryable,
        public readonly ?int $gatewayStatus = null,
    ) {
        parent::__construct($message);
    }
}
