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

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            beneficiaryName: (string) ($data['beneficiaryName'] ?? ''),
            iban: (string) ($data['iban'] ?? ''),
            bic: isset($data['bic']) ? (string) $data['bic'] : null,
            bankName: isset($data['bankName']) ? (string) $data['bankName'] : null,
            bankAddress: isset($data['bankAddress']) ? (string) $data['bankAddress'] : null,
            reference: isset($data['reference']) ? (string) $data['reference'] : null,
            amount: isset($data['amount']) ? (string) $data['amount'] : null,
            currency: isset($data['currency']) ? (string) $data['currency'] : null,
            expiresAt: isset($data['expiresAt']) && $data['expiresAt'] !== ''
                ? new \DateTimeImmutable((string) $data['expiresAt'])
                : null,
            metadata: is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
        );
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
