<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\PaymentAllocation;
use App\Models\SettlementEvent;

final readonly class SettlementResult
{
    public function __construct(
        public Invoice $invoice,
        public PaymentAllocation $allocation,
        public SettlementEvent $event,
        public bool $alreadySettled,
    ) {}
}
