<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PaymentAttempt;
use App\Services\PaymentResultService;
use Illuminate\Http\JsonResponse;

class PaymentResultController extends Controller
{
    public function show(string $reference, PaymentResultService $paymentResultService): JsonResponse
    {
        $paymentAttempt = $paymentResultService->find($reference);
        $application = $paymentAttempt->invoice->plan->application;

        return response()
            ->json([
                'data' => [
                    'reference' => $paymentAttempt->gateway_payment_id,
                    'status' => $this->publicStatus($paymentAttempt),
                    'amount' => $paymentAttempt->amount,
                    'currency' => $paymentAttempt->currency,
                    'paid_at' => $paymentAttempt->completed_at?->toISOString(),
                    'application_name' => $application?->name,
                    'return_url' => $this->validatedReturnUrl($application?->application_url),
                ],
            ])
            ->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache');
    }

    private function publicStatus(PaymentAttempt $paymentAttempt): string
    {
        return match ($paymentAttempt->status) {
            PaymentAttempt::StatusSucceeded => 'paid',
            PaymentAttempt::StatusInitiated, PaymentAttempt::StatusPending => 'pending',
            PaymentAttempt::StatusFailed => 'failed',
            PaymentAttempt::StatusExpired => 'expired',
            PaymentAttempt::StatusCancelled => 'cancelled',
            default => 'pending',
        };
    }

    private function validatedReturnUrl(mixed $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? null) !== 'https' || ! isset($parts['host'])) {
            return null;
        }

        return $url;
    }
}
