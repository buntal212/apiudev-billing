<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\IntegrationConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdminIntegrationTestCustomerRequest;
use App\Http\Requests\AdminIntegrationTestCustomerUpdateRequest;
use App\Http\Resources\ApplicationCustomerResource;
use App\Models\Application;
use App\Services\ApplicationIntegrationAuditService;
use App\Services\IntegrationCustomerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApplicationIntegrationTestController extends Controller
{
    public function store(
        AdminIntegrationTestCustomerRequest $request,
        Application $application,
        IntegrationCustomerService $customerService,
        ApplicationIntegrationAuditService $auditService,
    ): JsonResponse {
        $this->authorizeApplication($application);

        try {
            $result = $customerService->upsertAdminTest($application, $request->validated());
            $customer = $result['customer']->load('application');
            $operation = $result['created'] ? 'customer.created' : 'customer.updated';
            $audit = $auditService->record(
                request: $request,
                application: $application,
                token: null,
                operation: 'test.'.$operation,
                status: 'succeeded',
                externalCustomerId: $customer->external_customer_id,
                safeDetails: ['created' => $result['created'], 'is_test' => true],
            );

            return response()->json([
                'data' => new ApplicationCustomerResource($customer),
                'test' => [
                    'authentication_mode' => 'admin_internal',
                    'is_test' => true,
                    'created' => $result['created'],
                    'operation' => $operation,
                    'message' => 'Pengujian internal admin tidak membuktikan autentikasi Bearer eksternal.',
                ],
                'request_id' => $audit->request_id,
            ], $result['created'] ? Response::HTTP_CREATED : Response::HTTP_OK);
        } catch (IntegrationConflictException $exception) {
            $auditService->record(
                request: $request,
                application: $application,
                token: null,
                operation: 'test.customer.conflict',
                status: 'failed',
                externalCustomerId: $request->input('external_customer_id'),
                errorCode: $exception->safeCode,
                safeDetails: ['is_test' => true],
            );

            throw $exception;
        }
    }

    public function show(
        Request $request,
        Application $application,
        string $externalId,
        IntegrationCustomerService $customerService,
        ApplicationIntegrationAuditService $auditService,
    ): JsonResponse {
        $this->authorizeApplication($application);
        $customer = $customerService->find($application, $externalId, true);
        if ($customer === null) {
            $auditService->record(
                request: $request,
                application: $application,
                token: null,
                operation: 'test.customer.read',
                status: 'failed',
                externalCustomerId: $externalId,
                errorCode: 'customer_not_found',
                safeDetails: ['is_test' => true],
            );
            abort(Response::HTTP_NOT_FOUND, 'Pelanggan uji tidak ditemukan.');
        }

        $audit = $auditService->record(
            request: $request,
            application: $application,
            token: null,
            operation: 'test.customer.read',
            status: 'succeeded',
            externalCustomerId: $externalId,
            safeDetails: ['is_test' => true],
        );

        return response()->json([
            'data' => new ApplicationCustomerResource($customer),
            'test' => [
                'authentication_mode' => 'admin_internal',
                'is_test' => true,
                'operation' => 'customer.read',
                'message' => 'Pengujian internal admin tidak membuktikan autentikasi Bearer eksternal.',
            ],
            'request_id' => $audit->request_id,
        ]);
    }

    public function update(
        AdminIntegrationTestCustomerUpdateRequest $request,
        Application $application,
        string $externalId,
        IntegrationCustomerService $customerService,
        ApplicationIntegrationAuditService $auditService,
    ): JsonResponse {
        $this->authorizeApplication($application);

        try {
            $customer = $customerService
                ->updateAdminTest($application, $externalId, $request->validated())
                ->load('application');
            $audit = $auditService->record(
                request: $request,
                application: $application,
                token: null,
                operation: 'test.customer.updated',
                status: 'succeeded',
                externalCustomerId: $externalId,
                safeDetails: ['is_test' => true],
            );

            return response()->json([
                'data' => new ApplicationCustomerResource($customer),
                'test' => [
                    'authentication_mode' => 'admin_internal',
                    'is_test' => true,
                    'operation' => 'customer.updated',
                    'message' => 'Pengujian internal admin tidak membuktikan autentikasi Bearer eksternal.',
                ],
                'request_id' => $audit->request_id,
            ]);
        } catch (IntegrationConflictException $exception) {
            $auditService->record(
                request: $request,
                application: $application,
                token: null,
                operation: 'test.customer.conflict',
                status: 'failed',
                externalCustomerId: $externalId,
                errorCode: $exception->safeCode,
                safeDetails: ['is_test' => true],
            );

            throw $exception;
        }
    }

    private function authorizeApplication(Application $application): void
    {
        $this->authorize('view', $application);
        abort_unless($application->is_active, Response::HTTP_FORBIDDEN, 'Aplikasi tidak aktif untuk pengujian.');
    }
}
