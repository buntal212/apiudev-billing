<?php

namespace App\Services;

use App\Exceptions\IntegrationConflictException;
use App\Models\Application;
use App\Models\ApplicationCustomer;
use App\Models\ApplicationIntegrationToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class IntegrationCustomerService
{
    /**
     * @param  array<string, mixed>  $data
     * @return array{customer: ApplicationCustomer, created: bool}
     */
    public function upsert(
        Application $application,
        ApplicationIntegrationToken $token,
        array $data,
    ): array {
        if ($token->application_id !== $application->getKey()) {
            throw new IntegrationConflictException('application_token_mismatch', 'Token integrasi tidak terikat pada aplikasi ini.');
        }

        return DB::transaction(function () use ($application, $data): array {
            $lockedApplication = Application::query()->lockForUpdate()->findOrFail($application->getKey());
            if (! $lockedApplication->is_active) {
                throw ValidationException::withMessages([
                    'application' => ['Aplikasi tidak aktif untuk menerima sinkronisasi.'],
                ]);
            }

            $externalId = (string) $data['external_customer_id'];
            $now = now();
            $customer = ApplicationCustomer::query()
                ->where('application_id', $lockedApplication->getKey())
                ->where('external_customer_id', $externalId)
                ->lockForUpdate()
                ->first();

            if ($customer !== null && $customer->source !== 'integration') {
                throw new IntegrationConflictException(
                    'manual_customer_conflict',
                    'Pelanggan manual dengan ID eksternal tersebut tidak dapat diambil alih otomatis.',
                );
            }

            $attributes = [
                'application_id' => $lockedApplication->getKey(),
                'external_customer_id' => $externalId,
                'name' => $data['name'],
                'owner_name' => $data['owner_name'] ?? null,
                'email' => $data['email'] ?? null,
                'phone_number' => $data['phone'] ?? null,
                'is_active' => $data['status'] === 'active',
                'source' => 'integration',
                'first_synced_at' => $customer?->first_synced_at ?? $now,
                'last_synced_at' => $now,
                'sync_status' => 'synced',
                'sync_error_code' => null,
            ];

            if ($customer === null) {
                $customer = ApplicationCustomer::query()->create($attributes);

                return ['customer' => $customer, 'created' => true];
            }

            $customer->update($attributes);

            return ['customer' => $customer->refresh(), 'created' => false];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(
        Application $application,
        ApplicationIntegrationToken $token,
        string $externalId,
        array $data,
    ): ApplicationCustomer {
        if ($token->application_id !== $application->getKey()) {
            throw new IntegrationConflictException('application_token_mismatch', 'Token integrasi tidak terikat pada aplikasi ini.');
        }

        return DB::transaction(function () use ($application, $externalId, $data): ApplicationCustomer {
            $customer = ApplicationCustomer::query()
                ->where('application_id', $application->getKey())
                ->where('external_customer_id', $externalId)
                ->lockForUpdate()
                ->first();

            if ($customer === null) {
                abort(404, 'Pelanggan eksternal tidak ditemukan.');
            }
            if ($customer->source !== 'integration') {
                throw new IntegrationConflictException(
                    'manual_customer_conflict',
                    'Pelanggan manual tidak dapat diubah melalui integrasi.',
                );
            }

            $attributes = [];
            foreach (['name', 'owner_name', 'email'] as $field) {
                if (array_key_exists($field, $data)) {
                    $attributes[$field] = $data[$field];
                }
            }
            if (array_key_exists('phone', $data)) {
                $attributes['phone_number'] = $data['phone'];
            }
            if (array_key_exists('status', $data)) {
                $attributes['is_active'] = $data['status'] === 'active';
            }
            $attributes['last_synced_at'] = now();
            $attributes['sync_status'] = 'synced';
            $attributes['sync_error_code'] = null;
            $customer->update($attributes);

            return $customer->refresh();
        });
    }

    public function find(Application $application, string $externalId): ?ApplicationCustomer
    {
        return ApplicationCustomer::query()
            ->where('application_id', $application->getKey())
            ->where('external_customer_id', $externalId)
            ->with('application')
            ->first();
    }
}
