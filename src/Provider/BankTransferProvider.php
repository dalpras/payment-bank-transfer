<?php declare(strict_types=1);

namespace DalPraS\Payment\BankTransfer\Provider;

use DalPraS\Payment\BankTransfer\Config\BankTransferConfig;
use DalPraS\Payment\BankTransfer\Contract\BankTransferEventDispatcherInterface;
use DalPraS\Payment\BankTransfer\Contract\BankTransferReferenceGeneratorInterface;
use DalPraS\Payment\BankTransfer\Dto\BankTransferEvent;
use DalPraS\Payment\BankTransfer\Dto\BankTransferInstructions;
use DalPraS\Payment\BankTransfer\Enum\BankTransferEventType;
use DalPraS\Payment\BankTransfer\Exception\UnsupportedBankTransferOperation;
use DalPraS\Payment\BankTransfer\Support\BankTransferMoneyFormatter;
use DalPraS\Payment\BankTransfer\Support\NullBankTransferEventDispatcher;
use DalPraS\Payment\BankTransfer\Support\PaymentReferenceBankTransferReferenceGenerator;
use DalPraS\Payment\Contract\PaymentProviderInterface;
use DalPraS\Payment\Dto\AuthorizationResult;
use DalPraS\Payment\Dto\AuthorizeRequest;
use DalPraS\Payment\Dto\CancelRequest;
use DalPraS\Payment\Dto\CancelResult;
use DalPraS\Payment\Dto\CaptureRequest;
use DalPraS\Payment\Dto\CaptureResult;
use DalPraS\Payment\Dto\CheckoutRequest;
use DalPraS\Payment\Dto\CheckoutResponse;
use DalPraS\Payment\Dto\CompletionRequest;
use DalPraS\Payment\Dto\CompletionResult;
use DalPraS\Payment\Dto\RefundRequest;
use DalPraS\Payment\Dto\RefundResult;
use DalPraS\Payment\Dto\SyncRequest;
use DalPraS\Payment\Dto\SyncResult;
use DalPraS\Payment\Dto\VerificationResult;
use DalPraS\Payment\Dto\WebhookEvent;
use DalPraS\Payment\Enum\PaymentStatus;
use Psr\Http\Message\ServerRequestInterface;

final class BankTransferProvider implements PaymentProviderInterface
{
    public function __construct(
        private readonly BankTransferConfig $config,
        private readonly ?BankTransferEventDispatcherInterface $eventDispatcher = null,
        private readonly ?BankTransferReferenceGeneratorInterface $referenceGenerator = null,
        private readonly ?BankTransferMoneyFormatter $moneyFormatter = null,
    ) {
    }

    public function code(): string
    {
        return $this->config->providerCode;
    }

    public function createCheckout(CheckoutRequest $request): CheckoutResponse
    {
        $instructions = $this->buildInstructions($request);

        $metadata = $this->checkoutMetadata($request, $instructions);

        $payload = [
            'type' => 'manual_bank_transfer',
            'manual' => true,
            'instructions' => $instructions->toArray(),
            'metadata' => $metadata,
        ];

        $this->dispatch(
            BankTransferEventType::CheckoutCreated,
            $request->paymentReference,
            PaymentStatus::PendingCustomerAction,
            $instructions->reference,
            $payload,
            $request->correlationId,
        );

        $this->dispatch(
            BankTransferEventType::CustomerActionRequired,
            $request->paymentReference,
            PaymentStatus::PendingCustomerAction,
            $instructions->reference,
            $payload,
            $request->correlationId,
        );

        return new CheckoutResponse(
            status: PaymentStatus::PendingCustomerAction,
            redirectRequired: false,
            redirectUrl: null,
            providerPaymentId: $instructions->reference,
            providerToken: $instructions->reference,
            expiresAt: $instructions->expiresAt,
            raw: $payload,
            message: 'Manual bank transfer instructions created.',
            metadata: $metadata,
        );
    }

    public function completeCheckout(CompletionRequest $request): CompletionResult
    {
        return new CompletionResult(
            status: PaymentStatus::PendingCustomerAction,
            providerPaymentId: $request->expectedProviderPaymentId,
            transactionIds: [],
            message: 'Bank transfer checkout remains pending until the merchant confirms settlement.',
            raw: [
                'manual' => true,
                'query_params' => $request->queryParams,
                'body_params' => $request->bodyParams,
                'metadata' => $request->metadata,
            ],
            metadata: $this->operationMetadata($request->metadata, $request->expectedProviderPaymentId),
        );
    }

    public function authorize(AuthorizeRequest $request): AuthorizationResult
    {
        throw new UnsupportedBankTransferOperation('Manual bank transfer does not support authorization.');
    }

    public function capture(CaptureRequest $request): CaptureResult
    {
        $status = $this->statusFromMetadata($request->metadata, PaymentStatus::Captured);

        $this->dispatch(
            BankTransferEventType::PaymentConfirmed,
            $request->paymentReference,
            $status,
            $request->providerPaymentId,
            ['metadata' => $request->metadata, 'manual' => true],
        );

        return new CaptureResult(
            status: $status,
            providerPaymentId: $request->providerPaymentId,
            transactionIds: array_values(array_filter([$request->providerPaymentId], 'is_string')),
            message: 'Bank transfer manually marked as paid.',
            raw: ['manual' => true, 'metadata' => $request->metadata],
            metadata: $this->operationMetadata($request->metadata, $request->providerPaymentId, [
                'bank_transfer_confirmed_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ]),
        );
    }

    public function cancel(CancelRequest $request): CancelResult
    {
        $this->dispatch(
            BankTransferEventType::PaymentCancelled,
            $request->paymentReference,
            PaymentStatus::Cancelled,
            $request->providerPaymentId,
            ['metadata' => $request->metadata, 'manual' => true],
        );

        return new CancelResult(
            status: PaymentStatus::Cancelled,
            providerPaymentId: $request->providerPaymentId,
            transactionIds: [],
            message: 'Bank transfer manually cancelled.',
            raw: ['manual' => true, 'metadata' => $request->metadata],
            metadata: $this->operationMetadata($request->metadata, $request->providerPaymentId, [
                'bank_transfer_cancelled_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ]),
        );
    }

    public function refund(RefundRequest $request): RefundResult
    {
        $status = $this->statusFromMetadata($request->metadata, PaymentStatus::Refunded);

        $this->dispatch(
            BankTransferEventType::RefundMarked,
            $request->paymentReference,
            $status,
            $request->providerPaymentId,
            ['metadata' => $request->metadata, 'manual' => true],
        );

        return new RefundResult(
            status: $status,
            providerPaymentId: $request->providerPaymentId,
            transactionIds: array_values(array_filter([$request->providerPaymentId], 'is_string')),
            message: 'Bank transfer refund manually marked.',
            raw: ['manual' => true, 'metadata' => $request->metadata],
            metadata: $this->operationMetadata($request->metadata, $request->providerPaymentId, [
                'bank_transfer_refunded_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ]),
        );
    }

    public function sync(SyncRequest $request): SyncResult
    {
        $status = $this->statusFromMetadata($request->metadata, PaymentStatus::PendingCustomerAction);

        // Unknown is not a useful terminal state for a manual bank transfer: there
        // is no remote gateway to query. Recover from historical/bad reconciliation
        // by using durable confirmation metadata when available, otherwise keep the
        // transfer waiting for the customer/merchant action.
        if ($status === PaymentStatus::Unknown) {
            $status = $this->hasConfirmationEvidence($request->metadata)
                ? PaymentStatus::Captured
                : PaymentStatus::PendingCustomerAction;
        }

        $this->dispatch(
            BankTransferEventType::PaymentSynced,
            $request->paymentReference,
            $status,
            $request->providerPaymentId,
            ['metadata' => $request->metadata, 'manual' => true],
        );

        return new SyncResult(
            status: $status,
            providerPaymentId: $request->providerPaymentId,
            transactionIds: array_values(array_filter([$request->providerPaymentId], 'is_string')),
            message: 'Bank transfer state is manual; sync returns the requested/manual status.',
            raw: ['manual' => true, 'metadata' => $request->metadata],
            metadata: $this->operationMetadata($request->metadata, $request->providerPaymentId, [
                'status' => $status->value,
            ]),
        );
    }

    public function parseWebhook(ServerRequestInterface $request): WebhookEvent
    {
        return new WebhookEvent(
            providerCode: $this->code(),
            eventType: 'manual_bank_transfer.unsupported_webhook',
            providerPaymentId: null,
            payload: [],
            headers: $request->getHeaders(),
        );
    }

    public function verifyWebhook(WebhookEvent $event): VerificationResult
    {
        return new VerificationResult(
            verified: false,
            message: 'Manual bank transfer does not support provider webhooks.',
            raw: ['event_type' => $event->eventType],
        );
    }

    private function buildInstructions(CheckoutRequest $request): BankTransferInstructions
    {
        $reference = $this->referenceGenerator()->generate($request);
        $total = $request->amounts->grandTotal;

        return new BankTransferInstructions(
            beneficiaryName: $this->config->beneficiaryName,
            iban: $this->config->iban,
            bic: $this->config->bic,
            bankName: $this->config->bankName,
            bankAddress: $this->config->bankAddress,
            reference: $reference,
            amount: $this->moneyFormatter()->format($total),
            currency: $this->moneyFormatter()->currency($total),
            expiresAt: $this->expiration(),
            metadata: array_replace($this->config->metadata, $request->providerOptions['bank_transfer'] ?? []),
        );
    }


    /**
     * Metadata returned to payment-core during checkout creation.
     *
     * The generic keys make the provider compatible with the core enrichment
     * layer, while the bank_transfer_* keys keep the manual transfer details
     * explicit for applications that persist metadata on their Order entity.
     */
    private function checkoutMetadata(CheckoutRequest $request, BankTransferInstructions $instructions): array
    {
        return array_replace(
            $this->config->metadata,
            $request->metadata,
            [
                'provider' => $this->code(),
                'provider_payment_id' => $instructions->reference,
                'order_id' => $request->merchantReference,
                'payment_reference' => $request->paymentReference,
                'manual' => true,
                'bank_transfer_instructions' => $instructions->toArray(),
                'bank_transfer_reference' => $instructions->reference,
                'bank_transfer_iban' => $instructions->iban,
                'bank_transfer_bic' => $instructions->bic,
                'bank_transfer_beneficiary_name' => $instructions->beneficiaryName,
                'bank_transfer_amount' => $instructions->amount,
                'bank_transfer_currency' => $instructions->currency,
                'bank_transfer_expires_at' => $instructions->expiresAt?->format(DATE_ATOM),
            ],
        );
    }

    /**
     * Metadata returned after manual operations.
     *
     * Since there is no bank API operation id, the bank transfer reference remains
     * the provider id used by payment-core for later sync/cancel/refund calls.
     */
    private function operationMetadata(array $requestMetadata, ?string $providerPaymentId, array $extra = []): array
    {
        return array_replace(
            $requestMetadata,
            [
                'provider' => $this->code(),
                'provider_payment_id' => $providerPaymentId,
                'manual' => true,
                'bank_transfer_reference' => $providerPaymentId,
            ],
            $extra,
        );
    }

    private function expiration(): ?\DateTimeImmutable
    {
        if ($this->config->expiresAfterDays === null) {
            return null;
        }

        return (new \DateTimeImmutable())->modify('+' . $this->config->expiresAfterDays . ' days');
    }

    private function statusFromMetadata(array $metadata, PaymentStatus $default): PaymentStatus
    {
        $status = $metadata['status'] ?? $metadata['payment_status'] ?? null;
        if ($status instanceof PaymentStatus) {
            return $status;
        }

        if (is_string($status)) {
            return PaymentStatus::tryFrom($status) ?? $default;
        }

        return $default;
    }

    private function hasConfirmationEvidence(array $metadata): bool
    {
        foreach (['bank_transfer_confirmed_at', 'confirmed_at'] as $key) {
            $value = $metadata[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return true;
            }
        }

        return false;
    }

    private function dispatch(
        BankTransferEventType $type,
        string $paymentReference,
        PaymentStatus $status,
        ?string $providerPaymentId = null,
        array $payload = [],
        ?string $correlationId = null,
    ): void {
        if (!$this->config->dispatchEvents) {
            return;
        }

        $this->dispatcher()->dispatch(new BankTransferEvent(
            type: $type,
            providerCode: $this->code(),
            paymentReference: $paymentReference,
            status: $status,
            providerPaymentId: $providerPaymentId,
            payload: $payload,
            correlationId: $correlationId,
            occurredAt: new \DateTimeImmutable(),
        ));
    }

    private function dispatcher(): BankTransferEventDispatcherInterface
    {
        return $this->eventDispatcher ?? new NullBankTransferEventDispatcher();
    }

    private function referenceGenerator(): BankTransferReferenceGeneratorInterface
    {
        return $this->referenceGenerator ?? new PaymentReferenceBankTransferReferenceGenerator();
    }

    private function moneyFormatter(): BankTransferMoneyFormatter
    {
        return $this->moneyFormatter ?? new BankTransferMoneyFormatter();
    }
}
