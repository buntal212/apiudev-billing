<?php

namespace App\Services;

class DanaGatewayMerchant
{
    public function __construct(
        public readonly string $code,
        public readonly string $environment,
        public readonly string $baseUrl,
        public readonly string $apiKey,
    ) {}
}
