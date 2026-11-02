<?php

namespace App\Services;

use App\Models\ApplicationPaymentGateway;
use App\Models\CheckoutOutboxMessage;
use App\Models\Invoice;
use App\Models\PaymentAttempt;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class CheckoutService
{
    private MidtransGatewayProvisioningClient $midtransClient;
    private MidtransSecretStore $midtransSecretStore;

    public function __construct(
        private DanaGatewayClient $gatewayClient,
        private DanaGatewayMerchantResolver $merchantResolver,
        private SubscriptionBillingMerchantResolver $subscriptionMerchantResolver,
        ?MidtransGatewayProvisioningClient $midtransClient = null,
        ?MidtransSecretStore $midtransSecretStore = null,
    ) {
        $this->midtransClient = $midtransClient ?? app(MidtransGatewayProvisioningClient::class);
        $this->midtransSecretStore = $midtransSecretStore ?? app(MidtransSecretStore::class);
    }

    public function startCheckout(
        Invoice $invoice,
        string $requestIdempotencyKey,
        string $paymentMethod = 'dana_wallet',
        ?string $provider = null,
        ?string $bank = null,
    ): PaymentAttempt {
        $outbox = $this->prepareCheckout($invoice, $requestIdempotencyKey, $paymentMethod, $provider, $bank);
        $this->dispatch($outbox->getKey());

        return PaymentAttempt::query()->findOrFail($outbox->payment_attempt_id);
    }

    public function retryCheckout(PaymentAttempt $paymentAttempt): PaymentAttempt
    {
        $outboxId = DB::transaction(function () use ($paymentAttempt): int {
            $attempt = PaymentAttempt::query()
                ->lockForUpdate()
                ->findOrFail($paymentAttempt->getKey());
            $invoice = Invoice::query()
                ->with('plan')
                ->lockForUpdate()
                ->findOrFail($attempt->invoice_id);
            $outbox = CheckoutOutboxMessage::query()
                ->where('payment_attempt_id', $attempt->getKey())
                ->lockForUpdate()
                ->first();

            $this->assertRetryAllowed($attempt, $invoice, $outbox);

            return (int) $outbox->getKey();
        });
        $this->dispatch($outboxId);

        return PaymentAttempt::query()->findOrFail($paymentAttempt->getKey());
    }

    public function dispatchPending(int $limit = 50): int
    {
        $outboxIds = CheckoutOutboxMessage::query()
            ->where('status', CheckoutOutboxMessage::StatusPending)
            ->where(function ($query): void {
                $query->whereNull('available_at')->orWhere('available_at', '<=', now());
            })
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        foreach ($outboxIds as $outboxId) {
            $this->dispatch($outboxId);
        }

        return $outboxIds->count();
    }

    private function prepareCheckout(
        Invoice $invoice,
        string $requestIdempotencyKey,
        string $paymentMethod = 'dana_wallet',
        ?string $provider = null,
        ?string $bank = null,
    ): CheckoutOutboxMessage {
        try {
            return DB::transaction(function () use ($invoice, $requestIdempotencyKey, $paymentMethod, $provider, $bank): CheckoutOutboxMessage {
                $lockedInvoice = Invoice::query()
                    ->with('plan')
                    ->lockForUpdate()
                    ->findOrFail($invoice->getKey());
                $this->assertInvoiceCanStartCheckout($lockedInvoice);

                $resolvedProvider = $this->resolveProvider($lockedInvoice, $paymentMethod, $provider);

                $activeAttempt = PaymentAttempt::query()
                    ->where('invoice_id', $lockedInvoice->getKey())
                    ->whereIn('status', [PaymentAttempt::StatusInitiated, PaymentAttempt::StatusPending])
                    ->lockForUpdate()
                    ->latest('attempt_number')
                    ->first();

                if ($activeAttempt !== null) {
                    if ($activeAttempt->checkout_expires_at !== null && $activeAttempt->checkout_expires_at->lessThanOrEqualTo(now())) {
                        $activeAttempt->update([
                            'status' => PaymentAttempt::StatusExpired,
                            'completed_at' => now(),
                        ]);
                        $activeAttempt = null;
                    } elseif (($activeAttempt->metadata['payment_method'] ?? 'dana_wallet') !== $paymentMethod
                        || strtolower((string) $activeAttempt->provider) !== $resolvedProvider) {
                        $activeAttempt->update([
                            'status' => PaymentAttempt::StatusCancelled,
                            'completed_at' => now(),
                        ]);
                        $activeAttempt = null;
                    }
                }

                if ($activeAttempt !== null && strtolower((string) $activeAttempt->provider) === 'midtrans') {
                    $activeMetadata = is_array($activeAttempt->metadata) ? $activeAttempt->metadata : [];
                    $isQris = ($activeMetadata['payment_method'] ?? 'qris') === 'qris';
                    $hasInstructions = $isQris
                        ? (! empty($activeMetadata['qr_content']) || ! empty($activeMetadata['qr_url']) || ! empty($activeAttempt->checkout_url))
                        : (! empty($activeMetadata['payment_code']) || ! empty($activeAttempt->checkout_url));

                    if (! $hasInstructions) {
                        // Recover instructions if transaction already exists in Midtrans
                        $credential = \App\Models\MidtransMerchantCredential::query()
                            ->where('merchant_code', $activeAttempt->gateway_merchant_code)
                            ->where('environment', 'sandbox')
                            ->first();

                        if ($credential !== null && ! empty($credential->encrypted_server_key)) {
                            $serverKey = $credential->getServerKey($this->midtransSecretStore);
                            if (! empty($serverKey)) {
                                $statusResult = $this->midtransClient->querySandboxStatus(
                                    merchantCode: (string) $activeAttempt->gateway_merchant_code,
                                    serverKey: $serverKey,
                                    orderId: (string) $activeAttempt->merchant_reference,
                                    environment: 'sandbox',
                                );

                                if (($statusResult['status'] ?? '') === 'success' && ! empty($statusResult['data']['transaction_id'])) {
                                    $data = $statusResult['data'];
                                    $txId = $data['transaction_id'];
                                    $qrUrl = $data['qr_url'] ?? "https://api.sandbox.midtrans.com/v2/qris/{$txId}/qr-code";
                                    $qrString = $data['qr_string'] ?? null;
                                    $checkoutUrl = $data['checkout_url'] ?? $qrUrl;

                                    $activeMetadata['transaction_id'] = $txId;
                                    $activeMetadata['qr_url'] = $qrUrl;
                                    if ($qrString !== null) {
                                        $activeMetadata['qr_content'] = $qrString;
                                    }
                                    if (empty($activeMetadata['payment_instruction'])) {
                                        $activeMetadata['payment_instruction'] = [
                                            'type' => 'qris_mpm',
                                            'qr_content' => $qrString,
                                            'qr_url' => $qrUrl,
                                            'checkout_url' => $checkoutUrl,
                                            'message' => 'Pindai kode QRIS Midtrans Sandbox menggunakan aplikasi e-wallet atau mobile banking.',
                                        ];
                                    }

                                    $activeAttempt->update([
                                        'provider_reference' => $txId,
                                        'gateway_payment_id' => $txId,
                                        'checkout_url' => $checkoutUrl,
                                        'metadata' => $activeMetadata,
                                    ]);
                                } elseif (($statusResult['is_not_found'] ?? false) || ($statusResult['http_status'] ?? 0) === 404) {
                                    $activeAttempt->update([
                                        'status' => PaymentAttempt::StatusFailed,
                                        'failure_code' => 'midtrans_order_not_found',
                                        'failure_message' => 'Transaksi tidak ditemukan di Midtrans Sandbox.',
                                        'completed_at' => now(),
                                    ]);
                                    $activeAttempt = null;
                                }
                            }
                        }
                    }
                }

                if ($activeAttempt !== null) {
                    return CheckoutOutboxMessage::query()
                        ->where('payment_attempt_id', $activeAttempt->getKey())
                        ->lockForUpdate()
                        ->firstOrFail();
                }

                $attemptNumber = (int) PaymentAttempt::query()
                    ->where('invoice_id', $lockedInvoice->getKey())
                    ->lockForUpdate()
                    ->max('attempt_number') + 1;
                $merchantReference = $lockedInvoice->invoice_number.'-A'.$attemptNumber;

                if ($resolvedProvider === 'midtrans') {
                    $appId = $lockedInvoice->plan?->application_id ?? $lockedInvoice->customer?->application_id;
                    $mapping = ApplicationPaymentGateway::query()
                        ->where('application_id', $appId)
                        ->where('provider', 'midtrans')
                        ->where('environment', 'sandbox')
                        ->where('is_active', true)
                        ->first();

                    if ($mapping === null) {
                        throw ValidationException::withMessages([
                            'invoice' => ['Aplikasi invoice belum memiliki mapping merchant Midtrans Sandbox yang aktif.'],
                        ]);
                    }

                    $recipientMerchantCode = $mapping->gateway_merchant_code;
                    $credential = \App\Models\MidtransMerchantCredential::query()
                        ->where('merchant_code', $recipientMerchantCode)
                        ->where('environment', 'sandbox')
                        ->where('is_active', true)
                        ->first();

                    if ($credential === null || empty($credential->encrypted_server_key)) {
                        throw ValidationException::withMessages([
                            'invoice' => ['Merchant Midtrans penerima belum dikonfigurasi atau tidak aktif.'],
                        ]);
                    }

                    $attempt = PaymentAttempt::query()->create([
                        'invoice_id' => $lockedInvoice->getKey(),
                        'attempt_number' => $attemptNumber,
                        'provider' => 'midtrans',
                        'merchant_reference' => $merchantReference,
                        'gateway_merchant_code' => $recipientMerchantCode,
                        'gateway_environment' => 'sandbox',
                        'amount' => $lockedInvoice->amount,
                        'currency' => $lockedInvoice->currency,
                        'status' => PaymentAttempt::StatusInitiated,
                        'idempotency_key' => 'checkout:'.$lockedInvoice->getKey().':'.$requestIdempotencyKey,
                        'requested_at' => now(),
                        'metadata' => [
                            'invoice_number' => $lockedInvoice->invoice_number,
                            'application_customer_id' => $lockedInvoice->application_customer_id,
                            'subscription_id' => $lockedInvoice->subscription_id,
                            'application_id' => $appId,
                            'payment_method' => $paymentMethod,
                            'bank' => $bank,
                            'gateway_provider' => 'midtrans',
                        ],
                    ]);

                    return CheckoutOutboxMessage::query()->create([
                        'payment_attempt_id' => $attempt->getKey(),
                        'idempotency_key' => 'midtrans-checkout-attempt-'.$attempt->getKey(),
                        'status' => CheckoutOutboxMessage::StatusPending,
                    ]);
                }

                $environment = (string) config('services.dana_gateway.environment', 'sandbox');
                $recipientMerchantCode = $this->subscriptionMerchantResolver
                    ->resolveNewCheckoutMerchant($environment);

                $attempt = PaymentAttempt::query()->create([
                    'invoice_id' => $lockedInvoice->getKey(),
                    'attempt_number' => $attemptNumber,
                    'provider' => 'dana',
                    'merchant_reference' => $merchantReference,
                    'gateway_merchant_code' => $recipientMerchantCode,
                    'gateway_environment' => $environment,
                    'amount' => $lockedInvoice->amount,
                    'currency' => $lockedInvoice->currency,
                    'status' => PaymentAttempt::StatusInitiated,
                    'idempotency_key' => 'checkout:'.$lockedInvoice->getKey().':'.$requestIdempotencyKey,
                    'requested_at' => now(),
                    'metadata' => [
                        'invoice_number' => $lockedInvoice->invoice_number,
                        'application_customer_id' => $lockedInvoice->application_customer_id,
                        'subscription_id' => $lockedInvoice->subscription_id,
                        'payment_method' => $paymentMethod,
                        SubscriptionBillingMerchantResolver::RecipientMetadataKey => SubscriptionBillingMerchantResolver::CentralRecipient,
                    ],
                ]);

                return CheckoutOutboxMessage::query()->create([
                    'payment_attempt_id' => $attempt->getKey(),
                    'idempotency_key' => 'dana-checkout-attempt-'.$attempt->getKey(),
                    'status' => CheckoutOutboxMessage::StatusPending,
                ]);
            });
        } catch (QueryException $exception) {
            throw new RuntimeException('Checkout tidak dapat disiapkan secara aman.', previous: $exception);
        }
    }

    private function dispatch(int $outboxId): void
    {
        $claimed = DB::transaction(function () use ($outboxId): ?CheckoutOutboxMessage {
            $outbox = CheckoutOutboxMessage::query()
                ->with('paymentAttempt.invoice')
                ->lockForUpdate()
                ->findOrFail($outboxId);

            if ($outbox->status === CheckoutOutboxMessage::StatusCompleted) {
                return null;
            }

            if ($outbox->status === CheckoutOutboxMessage::StatusPending && $outbox->available_at?->isFuture()) {
                return null;
            }

            if ($outbox->status === CheckoutOutboxMessage::StatusProcessing && $outbox->locked_at?->greaterThan(now()->subMinute())) {
                return null;
            }

            $outbox->update([
                'status' => CheckoutOutboxMessage::StatusProcessing,
                'attempts' => $outbox->attempts + 1,
                'locked_at' => now(),
                'error_code' => null,
            ]);

            return $outbox->fresh(['paymentAttempt.invoice']);
        });

        if ($claimed === null) {
            return;
        }

        $attempt = $claimed->paymentAttempt;
        if (strtolower((string) $attempt->provider) === 'midtrans') {
            $this->dispatchMidtrans($claimed);

            return;
        }

        try {
            $merchant = $this->merchantResolver->resolve(
                $attempt->gateway_merchant_code,
                $attempt->gateway_environment,
            );
            $amountMinor = $this->amountMinor($attempt->amount);
            $gatewayPayment = $this->gatewayClient->findPaymentByMerchantOrderId($merchant, $attempt->merchant_reference);

            // Selalu baca order yang sudah ada lebih dulu. Payment pending tanpa
            // instruksi checkout adalah hasil parsial, bukan checkout siap bayar.
            // POST dengan reference dan Idempotency-Key outbox yang sama akan
            // melanjutkan payment gateway yang sama; tidak membuat payment baru.
            if ($gatewayPayment === null || ! $this->hasCompleteGatewayCheckout($gatewayPayment)) {
                $paymentMethod = $attempt->metadata['payment_method'] ?? 'dana_wallet';
                $gatewayPayment = $this->gatewayClient->createPayment(
                    merchant: $merchant,
                    idempotencyKey: $claimed->idempotency_key,
                    merchantOrderId: $attempt->merchant_reference,
                    amountMinor: $amountMinor,
                    metadata: [
                        'udev_invoice_id' => $attempt->invoice_id,
                        'udev_payment_attempt_id' => $attempt->getKey(),
                        'invoice_number' => $attempt->invoice->invoice_number,
                        'udev_sandbox_payment_method' => $paymentMethod,
                    ],
                );
            }

            $this->storeCheckout($claimed, $gatewayPayment, $amountMinor);
        } catch (ConnectionException $exception) {
            $this->markDispatchFailure($claimed, true, 'network_timeout');
        } catch (DanaGatewayException $exception) {
            $this->markDispatchFailure($claimed, $exception->retryable, $exception->retryable ? 'gateway_unavailable' : 'gateway_response_invalid');
        } catch (\Throwable) {
            $this->markDispatchFailure($claimed, false, 'checkout_processing_failed');
        }
    }

    /** @param array<string, mixed> $gatewayPayment */
    private function storeCheckout(CheckoutOutboxMessage $claimed, array $gatewayPayment, int $amountMinor): void
    {
        $gatewayPaymentId = $gatewayPayment['id'] ?? null;
        $merchantOrderId = $gatewayPayment['merchant_order_id'] ?? null;
        $gatewayAmount = $gatewayPayment['amount_minor'] ?? null;
        $currency = $gatewayPayment['currency'] ?? null;
        $status = $gatewayPayment['status'] ?? null;
        $checkoutUrl = $gatewayPayment['checkout_url'] ?? null;
        $expiresAt = $gatewayPayment['expires_at'] ?? null;

        $gatewayMetadata = is_array($gatewayPayment['metadata'] ?? null) ? $gatewayPayment['metadata'] : [];
        $instruction = is_array($gatewayMetadata['udev_sandbox_payment_instruction'] ?? null)
            ? $gatewayMetadata['udev_sandbox_payment_instruction']
            : null;
        $paymentCode = is_string($instruction['payment_code'] ?? null) && $instruction['payment_code'] !== ''
            ? $instruction['payment_code']
            : (is_string($gatewayMetadata['qr_content'] ?? null) ? $gatewayMetadata['qr_content'] : null);

        $hasValidCheckoutUrl = is_string($checkoutUrl) && filter_var($checkoutUrl, FILTER_VALIDATE_URL);
        $hasValidPaymentCode = is_string($paymentCode) && $paymentCode !== '';

        if (! is_string($gatewayPaymentId)
            || ! is_string($merchantOrderId)
            || ! is_int($gatewayAmount)
            || ! is_string($currency)
            || $status !== PaymentAttempt::StatusPending
            || (! $hasValidCheckoutUrl && ! $hasValidPaymentCode)
            || ! is_string($expiresAt)) {
            throw new DanaGatewayException('Respons checkout gateway tidak lengkap.', false);
        }

        $checkoutExpiresAt = CarbonImmutable::parse($expiresAt);
        if ($checkoutExpiresAt->lessThanOrEqualTo(now())) {
            throw new DanaGatewayException('Checkout gateway sudah tidak valid.', false);
        }

        DB::transaction(function () use ($claimed, $gatewayPaymentId, $merchantOrderId, $gatewayAmount, $currency, $checkoutUrl, $hasValidCheckoutUrl, $paymentCode, $instruction, $checkoutExpiresAt, $amountMinor): void {
            $outbox = CheckoutOutboxMessage::query()->lockForUpdate()->findOrFail($claimed->getKey());
            $attempt = PaymentAttempt::query()->lockForUpdate()->findOrFail($outbox->payment_attempt_id);
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($attempt->invoice_id);

            $this->assertInvoiceCanStartCheckout($invoice);
            if ($attempt->merchant_reference !== $merchantOrderId
                || $gatewayAmount !== $amountMinor
                || $currency !== $attempt->currency
                || $attempt->gateway_payment_id !== null && $attempt->gateway_payment_id !== $gatewayPaymentId) {
                throw new DanaGatewayException('Referensi atau nominal gateway tidak cocok.', false);
            }

            $currentMetadata = is_array($attempt->metadata) ? $attempt->metadata : [];
            if ($paymentCode !== null) {
                $currentMetadata['payment_code'] = $paymentCode;
            }
            if ($instruction !== null) {
                $currentMetadata['payment_instruction'] = $instruction;
                if (($instruction['type'] ?? null) === 'qris_mpm'
                    && is_string($instruction['qr_content'] ?? null)
                    && $instruction['qr_content'] !== '') {
                    $currentMetadata['qr_content'] = $instruction['qr_content'];
                }
            }

            $attempt->update([
                'gateway_payment_id' => $gatewayPaymentId,
                'checkout_url' => $hasValidCheckoutUrl ? $checkoutUrl : null,
                'checkout_expires_at' => $checkoutExpiresAt,
                'status' => PaymentAttempt::StatusPending,
                'metadata' => $currentMetadata,
            ]);
            $outbox->update([
                'status' => CheckoutOutboxMessage::StatusCompleted,
                'locked_at' => null,
                'available_at' => null,
                'error_code' => null,
            ]);
        });
    }

    /** @param array<string, mixed> $gatewayPayment */
    private function hasCompleteGatewayCheckout(array $gatewayPayment): bool
    {
        $checkoutUrl = $gatewayPayment['checkout_url'] ?? null;
        $metadata = is_array($gatewayPayment['metadata'] ?? null) ? $gatewayPayment['metadata'] : [];
        $instruction = is_array($metadata['udev_sandbox_payment_instruction'] ?? null)
            ? $metadata['udev_sandbox_payment_instruction']
            : [];
        $paymentCode = $instruction['payment_code'] ?? $metadata['payment_code'] ?? $metadata['qr_content'] ?? null;

        return ($gatewayPayment['status'] ?? null) === PaymentAttempt::StatusPending
            && is_string($gatewayPayment['expires_at'] ?? null)
            && ((is_string($checkoutUrl) && filter_var($checkoutUrl, FILTER_VALIDATE_URL))
                || (is_string($paymentCode) && $paymentCode !== ''));
    }

    private function markDispatchFailure(CheckoutOutboxMessage $claimed, bool $retryable, string $errorCode): void
    {
        DB::transaction(function () use ($claimed, $retryable, $errorCode): void {
            $outbox = CheckoutOutboxMessage::query()->lockForUpdate()->findOrFail($claimed->getKey());
            if ($outbox->status === CheckoutOutboxMessage::StatusCompleted) {
                return;
            }

            $outbox->update([
                'status' => $retryable ? CheckoutOutboxMessage::StatusPending : CheckoutOutboxMessage::StatusFailed,
                'locked_at' => null,
                'available_at' => $retryable ? now()->addSeconds(5) : null,
                'error_code' => $errorCode,
            ]);
        });
    }

    private function assertInvoiceCanStartCheckout(Invoice $invoice): void
    {
        if ($invoice->status !== Invoice::StatusPending) {
            throw ValidationException::withMessages([
                'invoice' => ['Invoice dengan status saat ini tidak dapat memulai checkout.'],
            ]);
        }

        if ($invoice->due_at->lessThanOrEqualTo(now())) {
            throw ValidationException::withMessages([
                'invoice' => ['Invoice telah kedaluwarsa dan tidak dapat memulai checkout.'],
            ]);
        }

        if ($invoice->currency !== 'IDR') {
            throw ValidationException::withMessages([
                'invoice' => ['Checkout DANA hanya mendukung mata uang IDR.'],
            ]);
        }

        $this->amountMinor($invoice->getRawOriginal('amount'));
    }

    private function resolveProvider(Invoice $invoice, string $paymentMethod, ?string $provider): string
    {
        if ($provider !== null && in_array(strtolower($provider), ['dana', 'midtrans'], true)) {
            return strtolower($provider);
        }

        if (in_array($paymentMethod, ['gopay', 'bank_transfer', 'va_bca', 'va_bni', 'va_permata', 'va_mandiri', 'echannel'], true)) {
            return 'midtrans';
        }

        if (in_array($paymentMethod, ['dana_wallet', 'va_pani', 'va_cimb', 'va_btpn'], true)) {
            return 'dana';
        }

        $appId = $invoice->plan?->application_id ?? $invoice->customer?->application_id;
        if ($appId !== null) {
            $midtransMapping = ApplicationPaymentGateway::query()
                ->where('application_id', $appId)
                ->where('provider', 'midtrans')
                ->where('environment', 'sandbox')
                ->where('is_active', true)
                ->first();

            $danaMapping = ApplicationPaymentGateway::query()
                ->where('application_id', $appId)
                ->where('provider', 'dana')
                ->where('environment', 'sandbox')
                ->where('is_active', true)
                ->first();

            if ($midtransMapping !== null && $danaMapping === null) {
                return 'midtrans';
            }
        }

        return 'dana';
    }

    private function dispatchMidtrans(CheckoutOutboxMessage $claimed): void
    {
        try {
            $attempt = $claimed->paymentAttempt;
            $credential = \App\Models\MidtransMerchantCredential::query()
                ->where('merchant_code', $attempt->gateway_merchant_code)
                ->where('environment', 'sandbox')
                ->first();

            if ($credential === null || empty($credential->encrypted_server_key)) {
                $this->markDispatchFailure($claimed, false, 'merchant_not_configured');

                return;
            }

            $serverKey = $credential->getServerKey($this->midtransSecretStore);
            if (empty($serverKey)) {
                $this->markDispatchFailure($claimed, false, 'merchant_not_configured');

                return;
            }
            $method = $attempt->metadata['payment_method'] ?? 'qris';
            $bank = $attempt->metadata['bank'] ?? null;

            $paymentType = 'qris';
            if ($method === 'gopay') {
                $paymentType = 'gopay';
            } elseif ($method === 'bank_transfer') {
                $paymentType = ($bank === 'mandiri' || $bank === 'echannel') ? 'echannel' : 'bank_transfer';
            } elseif (str_starts_with($method, 'va_')) {
                $bankName = substr($method, 3);
                if ($bankName === 'mandiri') {
                    $paymentType = 'echannel';
                    $bank = 'mandiri';
                } else {
                    $paymentType = 'bank_transfer';
                    $bank = $bankName;
                }
            } elseif ($method === 'echannel') {
                $paymentType = 'echannel';
                $bank = 'mandiri';
            }

            $amountMinor = $this->amountMinor((string) $attempt->getRawOriginal('amount'));
            $chargeResult = $this->midtransClient->createSandboxCharge(
                merchantCode: $attempt->gateway_merchant_code,
                serverKey: $serverKey,
                chargeDetails: [
                    'order_id' => $attempt->merchant_reference,
                    'amount' => $amountMinor,
                    'payment_type' => $paymentType,
                    'bank' => $bank ?: 'bca',
                    'customer_name' => $attempt->invoice->customer?->name ?? 'Pelanggan UDEV',
                    'customer_email' => $attempt->invoice->customer?->email ?? 'sandbox@udumbara.my.id',
                ],
                environment: 'sandbox',
            );

            if (($chargeResult['status'] ?? '') !== 'success') {
                $this->markDispatchFailure($claimed, false, 'gateway_response_invalid');

                return;
            }

            $this->storeMidtransCheckout($claimed, $chargeResult['data'] ?? [], $amountMinor, $paymentType, $bank);
        } catch (ConnectionException) {
            $this->markDispatchFailure($claimed, true, 'network_timeout');
        } catch (\Throwable) {
            $this->markDispatchFailure($claimed, false, 'checkout_processing_failed');
        }
    }

    /** @param array<string, mixed> $gatewayData */
    private function storeMidtransCheckout(
        CheckoutOutboxMessage $claimed,
        array $gatewayData,
        int $amountMinor,
        string $paymentType,
        ?string $bank,
    ): void {
        $transactionId = (string) ($gatewayData['transaction_id'] ?? $gatewayData['order_id'] ?? '');
        $orderId = (string) ($gatewayData['order_id'] ?? '');
        $qrString = $gatewayData['qr_string'] ?? null;
        $checkoutUrl = $gatewayData['checkout_url'] ?? null;
        $qrUrl = $gatewayData['qr_url'] ?? null;
        $vaNumbers = $gatewayData['va_numbers'] ?? [];
        $permataVaNumber = $gatewayData['permata_va_number'] ?? null;
        $billKey = $gatewayData['bill_key'] ?? null;
        $billerCode = $gatewayData['biller_code'] ?? null;

        if ($paymentType === 'qris') {
            if ($qrUrl === null && is_string($checkoutUrl) && str_contains($checkoutUrl, 'qr')) {
                $qrUrl = $checkoutUrl;
            }
            if ($qrUrl === null && ! empty($transactionId)) {
                $qrUrl = "https://api.sandbox.midtrans.com/v2/qris/{$transactionId}/qr-code";
            }
            if ($checkoutUrl === null && $qrUrl !== null) {
                $checkoutUrl = $qrUrl;
            }
        }

        $paymentCode = null;
        if (! empty($vaNumbers) && is_array($vaNumbers)) {
            $paymentCode = $vaNumbers[0]['va_number'] ?? null;
            if (! empty($vaNumbers[0]['bank'])) {
                $bank = $vaNumbers[0]['bank'];
            }
        }
        if ($paymentCode === null && ! empty($permataVaNumber)) {
            $paymentCode = $permataVaNumber;
            $bank = 'permata';
        }
        if ($paymentCode === null && ! empty($billKey)) {
            $paymentCode = $billKey;
            $bank = 'mandiri';
        }
        if ($paymentCode === null && ! empty($qrString)) {
            $paymentCode = $qrString;
        }

        // Validate completeness of instructions before marking checkout completed
        if ($paymentType === 'qris') {
            if (empty($qrString) && empty($qrUrl) && empty($checkoutUrl)) {
                throw new RuntimeException('Instruksi QRIS Midtrans tidak lengkap dari gateway.');
            }
        } elseif (in_array($paymentType, ['bank_transfer', 'echannel'], true)) {
            if (empty($paymentCode)) {
                throw new RuntimeException('Instruksi Virtual Account Midtrans tidak lengkap dari gateway.');
            }
        } elseif ($paymentType === 'gopay') {
            if (empty($checkoutUrl)) {
                throw new RuntimeException('Tautan pembayaran GoPay Midtrans tidak lengkap dari gateway.');
            }
        }

        $instruction = null;
        if ($paymentType === 'qris') {
            $instruction = [
                'type' => 'qris_mpm',
                'qr_content' => $qrString,
                'qr_url' => $qrUrl,
                'message' => 'Pindai kode QRIS Midtrans Sandbox menggunakan aplikasi e-wallet atau mobile banking.',
            ];
        } elseif (in_array($paymentType, ['bank_transfer', 'echannel'], true)) {
            $instruction = [
                'type' => 'virtual_account',
                'bank' => strtoupper($bank ?: 'BCA'),
                'payment_code' => $paymentCode,
                'bill_key' => $billKey,
                'biller_code' => $billerCode,
                'message' => 'Bayar melalui Virtual Account '.strtoupper($bank ?: 'BCA').' sebelum batas waktu.',
            ];
        } elseif ($paymentType === 'gopay') {
            $instruction = [
                'type' => 'gopay',
                'checkout_url' => $checkoutUrl,
                'message' => 'Lanjutkan pembayaran di aplikasi GoPay.',
            ];
        }

        $checkoutExpiresAt = now()->toImmutable()->addHours(24);

        DB::transaction(function () use (
            $claimed,
            $transactionId,
            $orderId,
            $checkoutUrl,
            $qrUrl,
            $paymentCode,
            $instruction,
            $qrString,
            $billKey,
            $billerCode,
            $bank,
            $paymentType,
        ): void {
            $outbox = CheckoutOutboxMessage::query()->lockForUpdate()->findOrFail($claimed->getKey());
            $attempt = PaymentAttempt::query()->lockForUpdate()->findOrFail($outbox->payment_attempt_id);
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($attempt->invoice_id);

            $this->assertInvoiceCanStartCheckout($invoice);

            $currentMetadata = is_array($attempt->metadata) ? $attempt->metadata : [];
            $currentMetadata['order_id'] = $orderId;
            $currentMetadata['transaction_id'] = $transactionId;
            $currentMetadata['payment_code'] = $paymentCode;
            $currentMetadata['payment_instruction'] = $instruction;
            if ($qrString !== null) {
                $currentMetadata['qr_content'] = $qrString;
            }
            if ($qrUrl !== null) {
                $currentMetadata['qr_url'] = $qrUrl;
            }
            if ($billKey !== null) {
                $currentMetadata['bill_key'] = $billKey;
                $currentMetadata['biller_code'] = $billerCode;
            }
            if ($bank !== null) {
                $currentMetadata['bank'] = $bank;
            }
            $currentMetadata['payment_type'] = $paymentType;

            $effectiveCheckoutUrl = $checkoutUrl ?: $qrUrl;
            $hasValidCheckoutUrl = is_string($effectiveCheckoutUrl) && filter_var($effectiveCheckoutUrl, FILTER_VALIDATE_URL);

            $attempt->update([
                'gateway_payment_id' => $transactionId ?: $orderId,
                'provider_reference' => $transactionId ?: $orderId,
                'checkout_url' => $hasValidCheckoutUrl ? $effectiveCheckoutUrl : null,
                'checkout_expires_at' => now()->toImmutable()->addHours(24),
                'status' => PaymentAttempt::StatusPending,
                'metadata' => $currentMetadata,
            ]);

            $outbox->update([
                'status' => CheckoutOutboxMessage::StatusCompleted,
                'locked_at' => null,
                'available_at' => null,
                'error_code' => null,
            ]);
        });
    }

    private function assertRetryAllowed(
        PaymentAttempt $attempt,
        Invoice $invoice,
        ?CheckoutOutboxMessage $outbox,
    ): void {
        if (! in_array($attempt->status, [PaymentAttempt::StatusInitiated, PaymentAttempt::StatusPending], true)) {
            throw ValidationException::withMessages([
                'payment_attempt' => ['Payment attempt dengan status saat ini tidak dapat diulang.'],
            ]);
        }

        $provider = strtolower((string) $attempt->provider);
        if (! in_array($provider, ['dana', 'midtrans'], true)
            || strtolower((string) $attempt->gateway_environment) !== 'sandbox'
            || ! is_string($attempt->gateway_merchant_code)
            || $attempt->gateway_merchant_code === ''
            || ! is_string($attempt->merchant_reference)
            || $attempt->merchant_reference === '') {
            throw ValidationException::withMessages([
                'payment_attempt' => ['Identitas checkout Sandbox tidak valid.'],
            ]);
        }

        $this->assertInvoiceCanStartCheckout($invoice);

        $expectedMerchantReference = $invoice->invoice_number.'-A'.$attempt->attempt_number;
        if ($attempt->merchant_reference !== $expectedMerchantReference
            || strtoupper((string) $attempt->currency) !== strtoupper((string) $invoice->currency)
            || $this->amountMinor($attempt->getRawOriginal('amount')) !== $this->amountMinor($invoice->getRawOriginal('amount'))) {
            throw ValidationException::withMessages([
                'payment_attempt' => ['Referensi, nominal, atau currency checkout tidak cocok dengan invoice.'],
            ]);
        }

        $metadata = is_array($attempt->metadata) ? $attempt->metadata : [];
        if ($provider === 'midtrans') {
            if ($invoice->plan === null) {
                throw ValidationException::withMessages([
                    'payment_attempt' => ['Paket invoice tidak ditemukan untuk memvalidasi merchant.'],
                ]);
            }

            $mapping = ApplicationPaymentGateway::query()
                ->where('application_id', $invoice->plan->application_id)
                ->where('provider', 'midtrans')
                ->where('environment', 'sandbox')
                ->where('gateway_merchant_code', $attempt->gateway_merchant_code)
                ->where('is_active', true)
                ->first();

            if ($mapping === null || $mapping->environment !== $attempt->gateway_environment) {
                throw ValidationException::withMessages([
                    'payment_attempt' => ['Mapping merchant checkout historis tidak lagi tersedia atau tidak cocok.'],
                ]);
            }

            $expectedOutboxKey = 'midtrans-checkout-attempt-'.$attempt->getKey();
        } elseif ($this->subscriptionMerchantResolver->isCentralAttempt($metadata)) {
            $configuredMerchant = $this->subscriptionMerchantResolver
                ->resolveNewCheckoutMerchant('sandbox');

            if ($configuredMerchant !== $attempt->gateway_merchant_code) {
                throw ValidationException::withMessages([
                    'payment_attempt' => ['Merchant penerima UDEV untuk checkout ini tidak lagi cocok dengan konfigurasi Sandbox.'],
                ]);
            }
            $expectedOutboxKey = 'dana-checkout-attempt-'.$attempt->getKey();
        } elseif ($invoice->plan === null) {
            throw ValidationException::withMessages([
                'payment_attempt' => ['Paket invoice tidak ditemukan untuk memvalidasi merchant.'],
            ]);
        } else {
            // Attempts without the central marker predate the policy. Keep them
            // on their stored application merchant; never reroute them.
            $mapping = ApplicationPaymentGateway::query()
                ->where('application_id', $invoice->plan->application_id)
                ->where('provider', 'dana')
                ->where('environment', 'sandbox')
                ->where('gateway_merchant_code', $attempt->gateway_merchant_code)
                ->where('is_active', true)
                ->first();

            if ($mapping === null || $mapping->environment !== $attempt->gateway_environment) {
                throw ValidationException::withMessages([
                    'payment_attempt' => ['Mapping merchant checkout historis tidak lagi tersedia atau tidak cocok.'],
                ]);
            }
            $expectedOutboxKey = 'dana-checkout-attempt-'.$attempt->getKey();
        }

        if ($outbox === null || $outbox->idempotency_key !== $expectedOutboxKey) {
            throw ValidationException::withMessages([
                'payment_attempt' => ['Outbox checkout tidak ditemukan atau tidak valid.'],
            ]);
        }

        if (! in_array($outbox->status, [
            CheckoutOutboxMessage::StatusFailed,
            CheckoutOutboxMessage::StatusPending,
            CheckoutOutboxMessage::StatusProcessing,
            CheckoutOutboxMessage::StatusCompleted,
        ], true)) {
            throw ValidationException::withMessages([
                'payment_attempt' => ['Status outbox checkout tidak dapat diulang.'],
            ]);
        }
    }

    private function amountMinor(string $amount): int
    {
        if (preg_match('/^([1-9][0-9]*)(?:\.0{1,2})?$/', $amount, $matches) !== 1 || strlen($matches[1]) > 15) {
            throw ValidationException::withMessages([
                'invoice' => ['Nominal invoice DANA harus berupa rupiah utuh tanpa pecahan.'],
            ]);
        }

        return (int) $matches[1];
    }
}
