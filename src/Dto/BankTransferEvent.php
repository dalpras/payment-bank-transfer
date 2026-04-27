<?php declare(strict_types=1);

namespace DalPraS\Payment\BankTransfer\Dto;

use DalPraS\Payment\BankTransfer\Enum\BankTransferEventType;
use DalPraS\Payment\Enum\PaymentStatus;

final readonly class BankTransferEvent
{
    public function __construct(
        public BankTransferEventType $type,
        public string $providerCode,
        public string $paymentReference,
        public PaymentStatus $status,
        public ?string $providerPaymentId = null,
        public array $payload = [],
        public ?string $correlationId = null,
        public ?\DateTimeImmutable $occurredAt = null,
    ) {
    }
}
