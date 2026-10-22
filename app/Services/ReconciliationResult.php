<?php

namespace App\Services;

use App\Models\PaymentAttempt;
use App\Models\PaymentReconciliationRun;
use Carbon\CarbonImmutable;

final readonly class ReconciliationResult
{
    public function __construct(
        public string $status,
        public PaymentAttempt $paymentAttempt,
        public ?PaymentReconciliationRun $run = null,
        public ?CarbonImmutable $retryAfterAt = null,
    ) {}
}
