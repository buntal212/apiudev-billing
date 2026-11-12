<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\DanaEnvironmentSetting;
use App\Models\DanaMerchantProvisioningAudit;
use App\Models\DanaPaymentFeeSetting;
use App\Services\DanaFeeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class DanaFeeSettingController extends Controller
{
    public function __construct(
        private readonly DanaFeeService $feeService,
    ) {}

    /**
     * List fee settings with filters, search, and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $environment = (string) $request->query('environment', 'sandbox');
        $environment = in_array($environment, ['sandbox', 'production'], true) ? $environment : 'sandbox';

        // Auto-seed official standard settings if empty for the selected environment
        $this->feeService->ensureDefaultSettings($environment);

        $query = DanaPaymentFeeSetting::query()
            ->with('application:id,name,code')
            ->where('environment', $environment);

        // Filter by application scope
        $appFilter = $request->query('application_id');
        if ($appFilter === 'default' || $appFilter === 'null') {
            $query->whereNull('application_id');
        } elseif (is_numeric($appFilter) && (int) $appFilter > 0) {
            $query->where('application_id', (int) $appFilter);
        }

        // Filter by fee type (dana / udev)
        $feeType = $request->query('fee_type');
        if (! empty($feeType) && in_array($feeType, ['dana', 'udev'], true)) {
            $query->where('fee_type', $feeType);
        }

        // Filter by payment method
        $paymentMethod = $request->query('payment_method');
        if (! empty($paymentMethod)) {
            $query->where('payment_method', $paymentMethod);
        }

        // Filter by status
        $status = $request->query('status');
        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        }

        // Search in payment method or notes
        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('payment_method', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%")
                    ->orWhereHas('application', function ($appQ) use ($search) {
                        $appQ->where('name', 'like', "%{$search}%")
                            ->orWhere('code', 'like', "%{$search}%");
                    });
            });
        }

        $perPage = (int) $request->query('per_page', 15);
        if ($perPage < 1) {
            $perPage = 15;
        }

        $fees = $query->orderBy('payment_method')
            ->orderBy('fee_type')
            ->orderByRaw('application_id IS NULL DESC')
            ->orderBy('id')
            ->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => $fees->items(),
            'meta' => [
                'current_page' => $fees->currentPage(),
                'per_page' => $fees->perPage(),
                'total' => $fees->total(),
                'last_page' => $fees->lastPage(),
                'has_more' => $fees->hasMorePages(),
            ],
        ]);
    }

    /**
     * Store a new fee setting with strict duplicate active validation.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'application_id' => ['nullable', 'integer', 'exists:applications,id'],
            'payment_method' => ['required', 'string', 'max:50'],
            'environment' => ['required', 'string', 'in:sandbox,production'],
            'fee_type' => ['required', 'string', 'in:dana,udev'],
            'fee_value' => ['required', 'numeric', 'min:0'],
            'fee_unit' => ['required', 'string', 'in:rupiah,percent'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validated['fee_unit'] === 'percent' && (float) $validated['fee_value'] > 100) {
            throw ValidationException::withMessages([
                'fee_value' => ['Nilai tarif persentase tidak boleh melebihi 100%.'],
            ]);
        }

        $isActive = $validated['is_active'] ?? true;
        $appId = $validated['application_id'] ?? null;

        // Check for duplicate active fee setting
        if ($isActive) {
            $this->assertNoActiveConflict(
                $validated['environment'],
                $validated['payment_method'],
                $validated['fee_type'],
                $appId
            );
        }

        $fee = DanaPaymentFeeSetting::query()->create([
            'application_id' => $appId,
            'payment_method' => $validated['payment_method'],
            'environment' => $validated['environment'],
            'fee_type' => $validated['fee_type'],
            'fee_value' => $validated['fee_value'],
            'fee_unit' => $validated['fee_unit'],
            'is_active' => $isActive,
            'notes' => $validated['notes'] ?? null,
        ]);

        $fee->load('application:id,name,code');

        $this->recordAudit($request, 'dana_fee_setting.created', 'succeeded', [
            'id' => $fee->id,
            'application_id' => $fee->application_id,
            'payment_method' => $fee->payment_method,
            'environment' => $fee->environment,
            'fee_type' => $fee->fee_type,
            'fee_value' => $fee->fee_value,
            'fee_unit' => $fee->fee_unit,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Pengaturan biaya DANA berhasil ditambahkan.',
            'data' => $fee,
        ], Response::HTTP_CREATED);
    }

    /**
     * Display a specific fee setting.
     */
    public function show(int $id): JsonResponse
    {
        $fee = DanaPaymentFeeSetting::query()
            ->with('application:id,name,code')
            ->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data' => $fee,
        ]);
    }

    /**
     * Update an existing fee setting.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $fee = DanaPaymentFeeSetting::query()->findOrFail($id);

        $validated = $request->validate([
            'fee_value' => ['sometimes', 'required', 'numeric', 'min:0'],
            'fee_unit' => ['sometimes', 'required', 'string', 'in:rupiah,percent'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $unit = $validated['fee_unit'] ?? $fee->fee_unit;
        $val = $validated['fee_value'] ?? $fee->fee_value;
        if ($unit === 'percent' && (float) $val > 100) {
            throw ValidationException::withMessages([
                'fee_value' => ['Nilai tarif persentase tidak boleh melebihi 100%.'],
            ]);
        }

        $isActive = array_key_exists('is_active', $validated) ? (bool) $validated['is_active'] : $fee->is_active;

        if ($isActive) {
            $this->assertNoActiveConflict(
                $fee->environment,
                $fee->payment_method,
                $fee->fee_type,
                $fee->application_id,
                $fee->id
            );
        }

        $fee->update($validated);
        $fee->load('application:id,name,code');

        $this->recordAudit($request, 'dana_fee_setting.updated', 'succeeded', [
            'id' => $fee->id,
            'application_id' => $fee->application_id,
            'payment_method' => $fee->payment_method,
            'environment' => $fee->environment,
            'fee_type' => $fee->fee_type,
            'fee_value' => $fee->fee_value,
            'fee_unit' => $fee->fee_unit,
            'is_active' => $fee->is_active,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Pengaturan biaya DANA berhasil diperbarui.',
            'data' => $fee,
        ]);
    }

    /**
     * Toggle fee status (active/inactive).
     */
    public function toggleStatus(Request $request, int $id): JsonResponse
    {
        $fee = DanaPaymentFeeSetting::query()->findOrFail($id);

        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $newActive = (bool) $validated['is_active'];

        if ($newActive) {
            $this->assertNoActiveConflict(
                $fee->environment,
                $fee->payment_method,
                $fee->fee_type,
                $fee->application_id,
                $fee->id
            );
        }

        $fee->update(['is_active' => $newActive]);
        $fee->load('application:id,name,code');

        $this->recordAudit($request, $newActive ? 'dana_fee_setting.activated' : 'dana_fee_setting.deactivated', 'succeeded', [
            'id' => $fee->id,
            'payment_method' => $fee->payment_method,
            'environment' => $fee->environment,
            'fee_type' => $fee->fee_type,
            'is_active' => $newActive,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Status biaya berhasil diperbarui.',
            'data' => $fee,
        ]);
    }

    /**
     * Delete a fee setting.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $fee = DanaPaymentFeeSetting::query()->findOrFail($id);

        $details = [
            'id' => $fee->id,
            'application_id' => $fee->application_id,
            'payment_method' => $fee->payment_method,
            'environment' => $fee->environment,
            'fee_type' => $fee->fee_type,
        ];

        $fee->delete();

        $this->recordAudit($request, 'dana_fee_setting.deleted', 'succeeded', $details);

        return response()->json([
            'status' => 'success',
            'message' => 'Pengaturan biaya DANA berhasil dihapus.',
        ]);
    }

    /**
     * Simulate fee calculation.
     */
    public function calculatePreview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'payment_method' => ['required', 'string'],
            'amount' => ['required', 'numeric', 'min:1'],
            'environment' => ['nullable', 'string', 'in:sandbox,production'],
            'application_id' => ['nullable', 'integer', 'exists:applications,id'],
        ]);

        $env = $validated['environment'] ?? 'sandbox';
        $method = $validated['payment_method'];
        $amount = (float) $validated['amount'];
        $appId = isset($validated['application_id']) ? (int) $validated['application_id'] : null;

        $primaryCode = DanaEnvironmentSetting::getPrimaryMerchantCode($env);
        $result = $this->feeService->calculateFee($primaryCode, $method, $amount, $env, $appId);

        return response()->json([
            'status' => 'success',
            'data' => $result,
        ]);
    }

    /**
     * Ensure no active conflict exists for the given scope.
     *
     * @throws ValidationException
     */
    private function assertNoActiveConflict(
        string $environment,
        string $paymentMethod,
        string $feeType,
        ?int $applicationId,
        ?int $ignoreId = null,
    ): void {
        $query = DanaPaymentFeeSetting::query()
            ->where('environment', $environment)
            ->where('payment_method', $paymentMethod)
            ->where('fee_type', $feeType)
            ->where('is_active', true);

        if ($applicationId === null) {
            $query->whereNull('application_id');
        } else {
            $query->where('application_id', $applicationId);
        }

        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }

        if ($query->exists()) {
            $scope = $applicationId ? 'aplikasi terpilih' : 'default global';
            throw ValidationException::withMessages([
                'payment_method' => [
                    "Tarif aktif untuk metode '{$paymentMethod}' tipe '{$feeType}' pada environment '{$environment}' ({$scope}) sudah ada. Nonaktifkan tarif sebelumnya terlebih dahulu.",
                ],
            ]);
        }
    }

    /**
     * Record an audit log for fee management operations.
     */
    private function recordAudit(Request $request, string $operation, string $status, array $safeDetails): void
    {
        DanaMerchantProvisioningAudit::query()->create([
            'admin_user_id' => (int) $request->user()->getAuthIdentifier(),
            'operation' => $operation,
            'merchant_code' => 'udev-central',
            'gateway_merchant_id' => null,
            'environment' => $safeDetails['environment'] ?? 'sandbox',
            'idempotency_fingerprint' => hash('sha256', "{$operation}:".json_encode($safeDetails)),
            'status' => $status,
            'gateway_status' => 200,
            'safe_details' => $safeDetails,
        ]);
    }
}
