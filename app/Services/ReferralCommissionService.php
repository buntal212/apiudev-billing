<?php

namespace App\Services;

use App\Models\CustomerReferral;
use App\Models\Invoice;
use App\Models\Partner;
use App\Models\PartnerAudit;
use App\Models\PaymentAllocation;
use App\Models\ReferralCommissionLedger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReferralCommissionService
{
    /**
     * Mencatat komisi hanya dari alokasi pembayaran yang telah terverifikasi.
     * Idempoten per invoice dan menghitung urutan pembayaran per warung (customer referral).
     */
    public function recordVerifiedAllocation(PaymentAllocation $allocation): ?ReferralCommissionLedger
    {
        $invoice = $allocation->invoice()->first();
        if ($invoice === null || $invoice->status !== Invoice::StatusPaid) {
            return null;
        }

        $referral = CustomerReferral::query()
            ->where('application_customer_id', $invoice->application_customer_id)
            ->where('application_id', $invoice->customer()->value('application_id'))
            ->first();

        if ($referral === null) {
            return null;
        }

        // Idempotency check per invoice: jika komisi untuk invoice ini sudah ada, kembalikan ledger yang ada
        $existing = ReferralCommissionLedger::query()
            ->where('invoice_id', $invoice->getKey())
            ->whereIn('commission_type', [
                ReferralCommissionLedger::TypeFirstPayment,
                ReferralCommissionLedger::TypeRenewal,
            ])
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($allocation, $invoice, $referral) {
            // Lock record referral warung untuk mencegah race condition concurrency perhitungan urutan pembayaran
            $lockedReferral = CustomerReferral::query()
                ->lockForUpdate()
                ->find($referral->getKey());

            if ($lockedReferral === null) {
                return null;
            }

            // Periksa ulang setelah lock
            $existing = ReferralCommissionLedger::query()
                ->where('invoice_id', $invoice->getKey())
                ->whereIn('commission_type', [
                    ReferralCommissionLedger::TypeFirstPayment,
                    ReferralCommissionLedger::TypeRenewal,
                ])
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            // Hitung urutan pembayaran per warung: jumlah komisi valid yang belum dibalikkan untuk warung ini
            $previousCommissionsCount = ReferralCommissionLedger::query()
                ->where('customer_referral_id', $lockedReferral->getKey())
                ->whereIn('commission_type', [
                    ReferralCommissionLedger::TypeFirstPayment,
                    ReferralCommissionLedger::TypeRenewal,
                ])
                ->where('status', '!=', ReferralCommissionLedger::StatusReversed)
                ->count();

            $isFirstPayment = ($previousCommissionsCount === 0);
            $commissionType = $isFirstPayment
                ? ReferralCommissionLedger::TypeFirstPayment
                : ReferralCommissionLedger::TypeRenewal;

            // Biaya admin gateway tidak masuk komisi; komisi bernilai nominal tetap sesuai aturan
            $amount = $isFirstPayment
                ? ReferralCommissionLedger::FirstPaymentAmount
                : ReferralCommissionLedger::RenewalAmount;

            $idempotencyKey = "comm:inv:{$invoice->getKey()}";

            return ReferralCommissionLedger::query()->firstOrCreate(
                ['idempotency_key' => $idempotencyKey],
                [
                    'partner_id' => $lockedReferral->partner_id,
                    'referral_code_id' => $lockedReferral->referral_code_id,
                    'customer_referral_id' => $lockedReferral->getKey(),
                    'invoice_id' => $invoice->getKey(),
                    'payment_allocation_id' => $allocation->getKey(),
                    'commission_type' => $commissionType,
                    'amount' => $amount,
                    'status' => ReferralCommissionLedger::StatusAvailable,
                    'notes' => "Komisi {$commissionType} untuk invoice #{$invoice->invoice_number}",
                    'verified_at' => $allocation->allocated_at ?? now(),
                ]
            );
        });
    }

    /**
     * Membalikkan komisi (reversal/refund/chargeback) dengan audit trail tanpa menghapus histori.
     */
    public function reverseCommission(Invoice $invoice, string $reason, ?User $user = null, ?string $ip = null): ReferralCommissionLedger
    {
        return DB::transaction(function () use ($invoice, $reason, $user, $ip) {
            $original = ReferralCommissionLedger::query()
                ->where('invoice_id', $invoice->getKey())
                ->whereIn('commission_type', [
                    ReferralCommissionLedger::TypeFirstPayment,
                    ReferralCommissionLedger::TypeRenewal,
                ])
                ->lockForUpdate()
                ->first();

            if ($original === null) {
                throw ValidationException::withMessages([
                    'invoice' => ['Tidak ditemukan komisi referral aktif untuk invoice ini.'],
                ]);
            }

            // Jika sudah dibalikkan, kembalikan ledger reversal yang ada (idempoten)
            if ($original->status === ReferralCommissionLedger::StatusReversed) {
                $existingReversal = ReferralCommissionLedger::query()
                    ->where('reversal_of_ledger_id', $original->getKey())
                    ->first();

                if ($existingReversal !== null) {
                    return $existingReversal;
                }
            }

            // Tandai ledger asal sebagai reversed
            $originalBefore = ['status' => $original->status, 'amount' => (string) $original->amount];
            $original->update(['status' => ReferralCommissionLedger::StatusReversed]);

            $reversalKey = "rev:inv:{$invoice->getKey()}";

            $reversal = ReferralCommissionLedger::query()->firstOrCreate(
                ['idempotency_key' => $reversalKey],
                [
                    'partner_id' => $original->partner_id,
                    'referral_code_id' => $original->referral_code_id,
                    'customer_referral_id' => $original->customer_referral_id,
                    'invoice_id' => $original->invoice_id,
                    'payment_allocation_id' => null,
                    'commission_type' => ReferralCommissionLedger::TypeReversal,
                    'amount' => -abs((float) $original->amount),
                    'status' => ReferralCommissionLedger::StatusReversed,
                    'reversal_of_ledger_id' => $original->getKey(),
                    'notes' => $reason,
                    'verified_at' => now(),
                ]
            );

            PartnerAudit::query()->create([
                'partner_id' => $original->partner_id,
                'referral_code_id' => $original->referral_code_id,
                'user_id' => $user?->getKey(),
                'action' => 'referral_commission.reversed',
                'before' => $originalBefore,
                'after' => [
                    'status' => ReferralCommissionLedger::StatusReversed,
                    'reversal_ledger_id' => $reversal->getKey(),
                    'reversal_amount' => (string) $reversal->amount,
                    'reason' => $reason,
                ],
                'ip_address' => $ip,
            ]);

            return $reversal;
        });
    }

    /**
     * Menghitung rincian saldo mitra: total penghasilan, saldo tersedia, saldo tertahan.
     */
    public function getPartnerBalances(Partner $partner): array
    {
        $grossEarnings = (float) $partner->commissionLedgers()
            ->whereIn('commission_type', [
                ReferralCommissionLedger::TypeFirstPayment,
                ReferralCommissionLedger::TypeRenewal,
            ])
            ->where('status', '!=', ReferralCommissionLedger::StatusReversed)
            ->sum('amount');

        $reversedAmount = (float) abs($partner->commissionLedgers()
            ->where('commission_type', ReferralCommissionLedger::TypeReversal)
            ->sum('amount'));

        $availableBalance = (float) $partner->commissionLedgers()
            ->where('status', ReferralCommissionLedger::StatusAvailable)
            ->sum('amount');

        $pendingBalance = (float) $partner->commissionLedgers()
            ->where('status', ReferralCommissionLedger::StatusHeld)
            ->sum('amount');

        return [
            'total_earnings' => number_format($grossEarnings, 2, '.', ''),
            'total_reversed' => number_format($reversedAmount, 2, '.', ''),
            'available_balance' => number_format(max(0, $availableBalance), 2, '.', ''),
            'pending_balance' => number_format(max(0, $pendingBalance), 2, '.', ''),
            'withdrawn_balance' => '0.00',
        ];
    }

    /**
     * Ringkasan metrik global referral dan komisi untuk dashboard admin.
     */
    public function getSummaryMetrics(): array
    {
        $totalPartners = Partner::query()->where('is_active', true)->count();
        $totalReferredWarungs = CustomerReferral::query()->count();

        $validCommissionsQuery = ReferralCommissionLedger::query()
            ->whereIn('commission_type', [
                ReferralCommissionLedger::TypeFirstPayment,
                ReferralCommissionLedger::TypeRenewal,
            ])
            ->where('status', '!=', ReferralCommissionLedger::StatusReversed);

        $totalCommissionsCount = (clone $validCommissionsQuery)->count();
        $totalEarnings = (float) (clone $validCommissionsQuery)->sum('amount');

        $totalReversalsAmount = (float) abs(ReferralCommissionLedger::query()
            ->where('commission_type', ReferralCommissionLedger::TypeReversal)
            ->sum('amount'));

        $totalAvailableBalance = (float) ReferralCommissionLedger::query()
            ->where('status', ReferralCommissionLedger::StatusAvailable)
            ->sum('amount');

        $totalPendingBalance = (float) ReferralCommissionLedger::query()
            ->where('status', ReferralCommissionLedger::StatusHeld)
            ->sum('amount');

        return [
            'total_partners' => $totalPartners,
            'total_referred_warungs' => $totalReferredWarungs,
            'total_commissions_count' => $totalCommissionsCount,
            'total_earnings' => number_format($totalEarnings, 2, '.', ''),
            'total_reversed' => number_format($totalReversalsAmount, 2, '.', ''),
            'total_available_balance' => number_format(max(0, $totalAvailableBalance), 2, '.', ''),
            'total_pending_balance' => number_format(max(0, $totalPendingBalance), 2, '.', ''),
            'total_withdrawn' => '0.00',
        ];
    }
}
