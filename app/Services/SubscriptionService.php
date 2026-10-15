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
}
