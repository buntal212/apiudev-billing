<?php

namespace App\Services;

use App\Models\ApplicationCustomer;
use App\Models\Subscription;
use App\Models\SubscriptionPeriodEvent;
use App\Models\SubscriptionPlan;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubscriptionService
{
    public function createSubscription(
        ApplicationCustomer $customer,
        SubscriptionPlan $plan,
        DateTimeInterface $startsAt,
        DateTimeInterface $expiresAt,
    ): Subscription {
        $startsAt = CarbonImmutable::instance($startsAt);
        $expiresAt = CarbonImmutable::instance($expiresAt);

        if ($expiresAt->lessThanOrEqualTo($startsAt)) {
            throw ValidationException::withMessages([
                'expires_at' => ['Tanggal berakhir harus setelah tanggal mulai.'],
            ]);
        }

        return DB::transaction(function () use ($customer, $plan, $startsAt, $expiresAt): Subscription {
            $lockedCustomer = ApplicationCustomer::query()
                ->lockForUpdate()
                ->findOrFail($customer->getKey());
            $lockedPlan = SubscriptionPlan::query()
                ->lockForUpdate()
                ->findOrFail($plan->getKey());

            if ($lockedCustomer->application_id !== $lockedPlan->application_id) {
                throw ValidationException::withMessages([
                    'subscription_plan_id' => ['Paket langganan harus berasal dari aplikasi yang sama dengan pelanggan.'],
                ]);
            }

            if (! $lockedPlan->is_active) {
                throw ValidationException::withMessages([
                    'subscription_plan_id' => ['Paket langganan yang dipilih tidak aktif.'],
                ]);
            }

            $hasConflictingSubscription = Subscription::query()
                ->where('application_customer_id', $lockedCustomer->id)
                ->where('status', Subscription::StatusActive)
                ->where('starts_at', '<', $expiresAt)
                ->where('expires_at', '>', $startsAt)
                ->lockForUpdate()
                ->exists();

            if ($hasConflictingSubscription) {
                throw ValidationException::withMessages([
                    'application_customer_id' => ['Pelanggan sudah memiliki langganan aktif pada periode tersebut.'],
                ]);
            }

            return Subscription::create([
                'application_customer_id' => $lockedCustomer->id,
                'subscription_plan_id' => $lockedPlan->id,
                'starts_at' => $startsAt,
                'expires_at' => $expiresAt,
                'status' => Subscription::StatusActive,
            ]);
        });
    }

    public function extendSubscription(
        Subscription $subscription,
        int $durationMonths,
        string $idempotencyKey,
        string $sourceType,
        ?string $sourceId = null,
        ?string $notes = null,
    ): SubscriptionPeriodEvent {
        if ($durationMonths < 1) {
            throw ValidationException::withMessages([
                'duration_months' => ['Durasi perpanjangan minimal 1 bulan.'],
            ]);
        }

        try {
            return DB::transaction(function () use (
                $subscription,
                $durationMonths,
                $idempotencyKey,
                $sourceType,
                $sourceId,
                $notes,
            ): SubscriptionPeriodEvent {
                $existingEvent = SubscriptionPeriodEvent::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($existingEvent) {
                    return $existingEvent;
                }

                $lockedSubscription = Subscription::query()
                    ->lockForUpdate()
                    ->findOrFail($subscription->getKey());

                if ($lockedSubscription->status !== Subscription::StatusActive) {
                    throw ValidationException::withMessages([
                        'subscription' => ['Langganan yang tidak aktif tidak dapat diperpanjang.'],
                    ]);
                }

                $previousExpiresAt = $lockedSubscription->expires_at->toImmutable();
                $newExpiresAt = ($previousExpiresAt->isFuture() ? $previousExpiresAt : now()->toImmutable())
                    ->addMonthsNoOverflow($durationMonths);

                $periodEvent = SubscriptionPeriodEvent::create([
                    'subscription_id' => $lockedSubscription->id,
                    'event_type' => 'renewal',
                    'previous_expires_at' => $previousExpiresAt,
                    'new_expires_at' => $newExpiresAt,
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'idempotency_key' => $idempotencyKey,
                    'notes' => $notes,
                    'created_at' => now(),
                ]);

                $lockedSubscription->update(['expires_at' => $newExpiresAt]);

                return $periodEvent;
            });
        } catch (QueryException $exception) {
            $existingEvent = SubscriptionPeriodEvent::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existingEvent) {
                return $existingEvent;
            }

            throw $exception;
        }
    }

    /**
     * Renewal policy is anchored to the verified gateway paid_at, never a redirect time:
     * active periods continue from their current expiry; expired or out-of-order periods
     * start at paid_at; a period already elapsed by delayed reconciliation remains expired.
     */
    public function renewFromVerifiedPayment(
        Subscription $subscription,
        int $durationMonths,
        string $idempotencyKey,
        string $sourceType,
        DateTimeInterface $verifiedPaidAt,
        ?string $sourceId = null,
        ?string $notes = null,
    ): SubscriptionPeriodEvent {
        if ($durationMonths < 1) {
            throw ValidationException::withMessages([
                'duration_months' => ['Durasi perpanjangan minimal 1 bulan.'],
            ]);
        }

        $verifiedPaidAt = CarbonImmutable::instance($verifiedPaidAt);
        if ($verifiedPaidAt->isFuture()) {
            throw ValidationException::withMessages([
                'paid_at' => ['Waktu pembayaran terverifikasi tidak boleh berada di masa depan.'],
            ]);
        }

        try {
            return DB::transaction(function () use (
                $subscription,
                $durationMonths,
                $idempotencyKey,
                $sourceType,
                $verifiedPaidAt,
                $sourceId,
                $notes,
            ): SubscriptionPeriodEvent {
                $existingEvent = SubscriptionPeriodEvent::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($existingEvent) {
                    return $existingEvent;
                }

                $lockedSubscription = Subscription::query()
                    ->lockForUpdate()
                    ->findOrFail($subscription->getKey());

                if ($lockedSubscription->status === Subscription::StatusCancelled) {
                    throw ValidationException::withMessages([
                        'subscription' => ['Langganan yang dibatalkan tidak dapat diperpanjang otomatis.'],
                    ]);
                }

                if (! in_array($lockedSubscription->status, [
                    Subscription::StatusActive,
                    Subscription::StatusExpired,
                ], true)) {
                    throw ValidationException::withMessages([
                        'subscription' => ['Status langganan tidak memenuhi syarat untuk settlement.'],
                    ]);
                }

                $continuesActivePeriod = $lockedSubscription->status === Subscription::StatusActive
                    && $lockedSubscription->expires_at->greaterThan($verifiedPaidAt);
                $periodStart = $continuesActivePeriod
                    ? $lockedSubscription->expires_at->toImmutable()
                    : $verifiedPaidAt;
                $newExpiresAt = $periodStart->addMonthsNoOverflow($durationMonths);
                $nextStatus = $newExpiresAt->isFuture()
                    ? Subscription::StatusActive
                    : Subscription::StatusExpired;
                $eventNotes = trim(implode(';', array_filter([
                    $notes,
                    'verified_paid_at='.$verifiedPaidAt->toISOString(),
                    'period_start='.$periodStart->toISOString(),
                    $continuesActivePeriod ? 'period_rule=continue_active_expiry' : 'period_rule=start_at_verified_paid_at',
                ])));

                $periodEvent = SubscriptionPeriodEvent::create([
                    'subscription_id' => $lockedSubscription->id,
                    'event_type' => 'renewal',
                    'previous_expires_at' => $lockedSubscription->expires_at,
                    'new_expires_at' => $newExpiresAt,
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'idempotency_key' => $idempotencyKey,
                    'notes' => $eventNotes,
                    'created_at' => now(),
                ]);

                $updates = [
                    'expires_at' => $newExpiresAt,
                    'status' => $nextStatus,
                ];
                if (! $continuesActivePeriod) {
                    $updates['starts_at'] = $periodStart;
                }
                $lockedSubscription->update($updates);

                return $periodEvent;
            });
        } catch (QueryException $exception) {
            $existingEvent = SubscriptionPeriodEvent::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existingEvent) {
                return $existingEvent;
            }

            throw $exception;
        }
    }
}
