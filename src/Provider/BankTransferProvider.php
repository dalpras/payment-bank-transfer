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

        $payload = [
            'type' => 'manual_bank_transfer',
            'manual' => true,
            'instructions' => $instructions->toArray(),
            'metadata' => array_replace($this->config->metadata, $request->metadata),
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
            ],
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
        );
    }

    public function sync(SyncRequest $request): SyncResult
    {
        $status = $this->statusFromMetadata($request->metadata, PaymentStatus::PendingCustomerAction);

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
        $total = $request->amounts->total ?? null;

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
            return PaymentStatus::TryFrom($status) ?? $default;
        }

        return $default;
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
