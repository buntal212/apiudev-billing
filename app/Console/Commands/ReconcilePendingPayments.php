<?php

namespace App\Console\Commands;

use App\Services\PaymentReconciliationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('payments:reconcile-pending {--limit=25 : Jumlah maksimum payment attempt yang diperiksa}')]
#[Description('Merekonsiliasi payment attempt DANA Sandbox yang masih pending')]
class ReconcilePendingPayments extends Command
{
    public function handle(PaymentReconciliationService $reconciliationService): int
    {
        $limit = max(1, min(100, (int) $this->option('limit')));
        $processed = $reconciliationService->reconcilePending($limit);

        $this->info("{$processed} payment attempt diperiksa.");

        return self::SUCCESS;
    }
}
