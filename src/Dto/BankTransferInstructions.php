<?php declare(strict_types=1);

namespace DalPraS\Payment\BankTransfer\Dto;

final readonly class BankTransferInstructions
{
    public function __construct(
        public string $beneficiaryName,
        public string $iban,
        public ?string $bic = null,
        public ?string $bankName = null,
        public ?string $bankAddress = null,
        public ?string $reference = null,
        public ?string $amount = null,
        public ?string $currency = null,
        public ?\DateTimeImmutable $expiresAt = null,
        public array $metadata = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'beneficiaryName' => $this->beneficiaryName,
            'iban' => $this->iban,
            'bic' => $this->bic,
            'bankName' => $this->bankName,
            'bankAddress' => $this->bankAddress,
            'reference' => $this->reference,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'expiresAt' => $this->expiresAt?->format(DATE_ATOM),
            'metadata' => $this->metadata,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }
}
