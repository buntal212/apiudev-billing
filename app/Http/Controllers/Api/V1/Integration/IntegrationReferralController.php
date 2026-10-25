<?php

namespace App\Http\Controllers\Api\V1\Integration;

use App\Http\Controllers\Controller;
use App\Http\Requests\IntegrationAttachReferralRequest;
use App\Http\Requests\IntegrationValidateReferralRequest;
use App\Models\Application;
use App\Models\ApplicationIntegrationToken;
use App\Models\CustomerReferral;
use App\Services\ApplicationIntegrationAuditService;
use App\Services\IntegrationCustomerService;
use App\Services\IntegrationReferralService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IntegrationReferralController extends Controller
{
    public function validate(IntegrationValidateReferralRequest $request, IntegrationReferralService $referrals, ApplicationIntegrationAuditService $audits): JsonResponse
    {
        [$application, $token] = $this->context($request);
        $data = $referrals->validate($application, $request->string('referral_code')->toString());
        $audits->record($request, $application, $token, 'referral.validated', 'succeeded', safeDetails: ['type' => $data['type']]);

        return $this->response($request, $data);
    }

    public function attach(IntegrationAttachReferralRequest $request, string $externalId, IntegrationCustomerService $customers, IntegrationReferralService $referrals, ApplicationIntegrationAuditService $audits): JsonResponse
    {
        [$application, $token] = $this->context($request);
        $customer = $customers->find($application, $externalId);
        abort_if($customer === null, Response::HTTP_NOT_FOUND, 'Pelanggan eksternal tidak ditemukan.');
        $referral = $referrals->attach($application, $customer, $request->string('referral_code')->toString());
        $data = ['customer_external_id' => $customer->external_customer_id, 'referral' => ['code' => $referral->referral_code, 'type' => $referral->referral_type, 'base_price' => $referral->base_price, 'discount_amount' => $referral->discount_amount, 'customer_price' => $referral->customer_price, 'locked_at' => $referral->locked_at?->toISOString()]];
        $audits->record($request, $application, $token, 'referral.locked', 'succeeded', $externalId, safeDetails: ['type' => $referral->referral_type]);

        return $this->response($request, $data, Response::HTTP_CREATED);
    }

    public function subscription(Request $request, string $externalId, IntegrationCustomerService $customers, ApplicationIntegrationAuditService $audits): JsonResponse
    {
        [$application, $token] = $this->context($request);
        $customer = $customers->find($application, $externalId);
        abort_if($customer === null, Response::HTTP_NOT_FOUND, 'Pelanggan eksternal tidak ditemukan.');
        $subscription = $customer->subscriptions()->with('plan')->latest('expires_at')->first();
        $referral = CustomerReferral::query()->where('application_customer_id', $customer->getKey())->first();
        $data = ['customer_external_id' => $customer->external_customer_id, 'subscription' => $subscription ? ['status' => $subscription->status, 'starts_at' => $subscription->starts_at?->toISOString(), 'expires_at' => $subscription->expires_at?->toISOString(), 'plan_code' => $subscription->plan?->code] : null, 'referral' => $referral ? ['code' => $referral->referral_code, 'type' => $referral->referral_type, 'base_price' => $referral->base_price, 'discount_amount' => $referral->discount_amount, 'customer_price' => $referral->customer_price, 'locked_at' => $referral->locked_at?->toISOString()] : null];
        $audits->record($request, $application, $token, 'customer.subscription.read', 'succeeded', $externalId);

        return $this->response($request, $data);
    }

    /** @return array{0: Application, 1: ApplicationIntegrationToken} */
    private function context(Request $request): array
    {
        return [$request->attributes->get('integration_application'), $request->attributes->get('integration_token')];
    }

    /** @param array<string, mixed> $data */
    private function response(Request $request, array $data, int $status = Response::HTTP_OK): JsonResponse
    {
        return response()->json(['data' => $data, 'request_id' => $request->attributes->get('integration_request_id')], $status);
    }
}
