<?php

namespace App\Console\Commands;

use App\Services\CheckoutService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('checkout:dispatch-pending {--limit=50 : Jumlah maksimum outbox yang diproses}')]
#[Description('Memproses ulang checkout DANA pending dari outbox secara aman')]
class DispatchPendingCheckouts extends Command
{
    public function handle(CheckoutService $checkoutService): int
    {
        $limit = max(1, min(100, (int) $this->option('limit')));
        $processed = $checkoutService->dispatchPending($limit);

        $this->info("{$processed} outbox checkout diproses.");

        return self::SUCCESS;
    }
}
