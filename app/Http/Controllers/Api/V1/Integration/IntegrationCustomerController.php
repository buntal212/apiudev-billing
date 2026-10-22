<?php

namespace App\Http\Controllers\Api\V1\Integration;

use App\Exceptions\IntegrationConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\IntegrationStoreCustomerRequest;
use App\Http\Requests\IntegrationUpdateCustomerRequest;
use App\Http\Resources\ApplicationCustomerResource;
use App\Models\Application;
use App\Models\ApplicationIntegrationToken;
use App\Services\ApplicationIntegrationAuditService;
use App\Services\IntegrationCustomerService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IntegrationCustomerController extends Controller
{
    public function store(
        IntegrationStoreCustomerRequest $request,
        IntegrationCustomerService $customerService,
        ApplicationIntegrationAuditService $auditService,
    ): ApplicationCustomerResource {
        [$application, $token] = $this->context($request);

        try {
            $result = $customerService->upsert($application, $token, $request->validated());
            $customer = $result['customer']->load('application');
            $auditService->record(
                request: $request,
                application: $application,
                token: $token,
                operation: $result['created'] ? 'customer.created' : 'customer.updated',
                status: 'succeeded',
                externalCustomerId: $customer->external_customer_id,
                safeDetails: ['created' => $result['created']],
            );

            return (new ApplicationCustomerResource($customer))
                ->additional(['sync' => ['status' => 'accepted']]);
        } catch (IntegrationConflictException $exception) {
            $auditService->record(
                request: $request,
                application: $application,
                token: $token,
                operation: 'customer.conflict',
                status: 'failed',
                externalCustomerId: $request->input('external_customer_id'),
                errorCode: $exception->safeCode,
            );

            throw $exception;
        }
    }

    public function show(
        Request $request,
        string $externalId,
        IntegrationCustomerService $customerService,
        ApplicationIntegrationAuditService $auditService,
    ): ApplicationCustomerResource {
        [$application, $token] = $this->context($request);
        $customer = $customerService->find($application, $externalId);
        if ($customer === null) {
            $auditService->record(
                request: $request,
                application: $application,
                token: $token,
                operation: 'customer.read',
                status: 'failed',
                externalCustomerId: $externalId,
                errorCode: 'customer_not_found',
            );
            abort(Response::HTTP_NOT_FOUND, 'Pelanggan eksternal tidak ditemukan.');
        }

        $auditService->record(
            request: $request,
            application: $application,
            token: $token,
            operation: 'customer.read',
            status: 'succeeded',
            externalCustomerId: $externalId,
        );

        return new ApplicationCustomerResource($customer);
    }

    public function update(
        IntegrationUpdateCustomerRequest $request,
        string $externalId,
        IntegrationCustomerService $customerService,
        ApplicationIntegrationAuditService $auditService,
    ): ApplicationCustomerResource {
        [$application, $token] = $this->context($request);

        try {
            $customer = $customerService->update(
                application: $application,
                token: $token,
                externalId: $externalId,
                data: $request->validated(),
            )->load('application');
            $auditService->record(
                request: $request,
                application: $application,
                token: $token,
                operation: 'customer.updated',
                status: 'succeeded',
                externalCustomerId: $externalId,
            );

            return new ApplicationCustomerResource($customer);
        } catch (IntegrationConflictException $exception) {
            $auditService->record(
                request: $request,
                application: $application,
                token: $token,
                operation: 'customer.conflict',
                status: 'failed',
                externalCustomerId: $externalId,
                errorCode: $exception->safeCode,
            );

            throw $exception;
        }
    }

    /**
     * @return array{0: Application, 1: ApplicationIntegrationToken}
     */
    private function context(Request $request): array
    {
        /** @var Application $application */
        $application = $request->attributes->get('integration_application');
        /** @var ApplicationIntegrationToken $token */
        $token = $request->attributes->get('integration_token');

        return [$application, $token];
    }
}
