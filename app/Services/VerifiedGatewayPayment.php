<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use DateTimeInterface;

final readonly class VerifiedGatewayPayment
{
    public function __construct(
        public string $provider,
        public string $gatewayPaymentId,
        public string $merchantOrderId,
        public int $amountMinor,
        public string $currency,
        public string $merchantCode,
        public string $environment,
        public CarbonImmutable $paidAt,
    ) {}

    public static function paid(
        string $provider,
        string $gatewayPaymentId,
        string $merchantOrderId,
        int $amountMinor,
        string $currency,
        string $merchantCode,
        string $environment,
        DateTimeInterface $paidAt,
    ): self {
        return new self(
            provider: strtolower($provider),
            gatewayPaymentId: $gatewayPaymentId,
            merchantOrderId: $merchantOrderId,
            amountMinor: $amountMinor,
            currency: strtoupper($currency),
            merchantCode: $merchantCode,
            environment: $environment,
            paidAt: CarbonImmutable::instance($paidAt),
        );
    }
}
