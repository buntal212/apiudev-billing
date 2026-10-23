<?php

namespace App\Http\Resources;

use App\Models\PaymentAttempt;
use App\Models\PaymentReconciliationRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BillingGatewayTransactionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PaymentAttempt $attempt */
        $attempt = $this->resource;
        $invoice = $attempt->invoice;
        $customer = $invoice->customer;
        $application = $customer->application;
        $run = $attempt->latestReconciliationRun;
        $allocation = $attempt->paymentAllocation;

        return [
            'id' => $attempt->id,
            'application' => [
                'id' => $application->id,
                'code' => $application->code,
                'name' => $application->name,
            ],
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
            ],
            'invoice' => [
                'id' => $invoice->id,
                'number' => $invoice->invoice_number,
                'status' => $invoice->status,
                'paid_at' => $invoice->paid_at?->toISOString(),
            ],
            'payment_attempt' => [
                'id' => $attempt->id,
                'number' => $attempt->attempt_number,
                'status' => $attempt->status,
                'requested_at' => $attempt->requested_at?->toISOString(),
                'completed_at' => $attempt->completed_at?->toISOString(),
            ],
            'gateway' => [
                'provider' => strtolower($attempt->provider),
                'environment' => $attempt->gateway_environment,
                'merchant_code' => $attempt->gateway_merchant_code,
                'transaction_id' => $attempt->gateway_payment_id,
                'status' => $run?->gateway_status,
                'record_status' => $attempt->gateway_payment_id === null
                    ? 'gateway_not_found'
                    : 'recorded_in_billing',
            ],
            'references' => [
                'merchant' => $attempt->merchant_reference,
                'provider' => $attempt->provider_reference,
            ],
            'amount' => $attempt->amount,
            'currency' => $attempt->currency,
            'billing_sync' => $this->billingSyncStatus($attempt),
            'confirmation' => [
                'reconciliation_status' => $run?->status,
                'gateway_status' => $run?->gateway_status,
                'verified_at' => $run?->verified_at?->toISOString(),
                'last_checked_at' => $run?->finished_at?->toISOString(),
                'error_code' => $run?->error_code,
                'payment_proof_valid' => $run?->status === PaymentReconciliationRun::StatusPaid
                    && $allocation !== null,
            ],
            'settlement' => [
                'allocated_at' => $allocation?->allocated_at?->toISOString(),
                'subscription' => $invoice->subscription ? [
                    'id' => $invoice->subscription->id,
                    'status' => $invoice->subscription->status,
                    'starts_at' => $invoice->subscription->starts_at?->toISOString(),
                    'expires_at' => $invoice->subscription->expires_at?->toISOString(),
                    'last_update_at' => $invoice->subscription->latestPeriodEvent?->created_at?->toISOString(),
                ] : null,
                'events' => $attempt->settlementEvents->map(fn ($event): array => [
                    'event_type' => $event->event_type,
                    'status' => $event->status,
                    'error_code' => $event->error_code,
                    'processed_at' => $event->processed_at?->toISOString(),
                ])->values()->all(),
            ],
            'anomaly' => $this->anomaly($attempt),
            'created_at' => $attempt->created_at?->toISOString(),
        ];
    }

    /** @return array{status: string, label: string} */
    private function billingSyncStatus(PaymentAttempt $attempt): array
    {
        $run = $attempt->latestReconciliationRun;

        if ($attempt->gateway_payment_id === null) {
            return ['status' => 'gateway_not_found', 'label' => 'Transaksi gateway belum ditemukan'];
        }

        if ($run?->status === PaymentReconciliationRun::StatusFailed) {
            return ['status' => 'sync_failed', 'label' => 'Gagal disinkronkan'];
        }

        if ($run?->status === PaymentReconciliationRun::StatusPaid && $attempt->paymentAllocation !== null) {
            return ['status' => 'synchronized', 'label' => 'Terverifikasi dan disinkronkan'];
        }

        if ($attempt->status === PaymentAttempt::StatusSucceeded) {
            return ['status' => 'billing_update_pending', 'label' => 'Pembayaran terverifikasi, pembaruan billing belum lengkap'];
        }

        return ['status' => 'awaiting_confirmation', 'label' => 'Menunggu konfirmasi gateway'];
    }

    /** @return array{code: string, message: string}|null */
    private function anomaly(PaymentAttempt $attempt): ?array
    {
        $status = $this->billingSyncStatus($attempt)['status'];

        return match ($status) {
            'gateway_not_found' => [
                'code' => 'gateway_transaction_missing',
                'message' => 'Belum ada ID transaksi gateway pada percobaan pembayaran ini.',
            ],
            'sync_failed' => [
                'code' => 'billing_sync_failed',
                'message' => 'Rekonsiliasi terakhir gagal; data tidak diubah otomatis.',
            ],
            'billing_update_pending' => [
                'code' => 'billing_settlement_missing',
                'message' => 'Bukti gateway ada, tetapi settlement atau pembaruan langganan belum lengkap.',
            ],
            default => null,
        };
    }
}
