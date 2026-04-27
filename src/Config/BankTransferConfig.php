<?php declare(strict_types=1);

namespace DalPraS\Payment\BankTransfer\Config;

final readonly class BankTransferConfig
{
    public function __construct(
        public string $beneficiaryName,
        public string $iban,
        public ?string $bic = null,
        public ?string $bankName = null,
        public ?string $bankAddress = null,
        public ?int $expiresAfterDays = null,
        public string $providerCode = 'bank_transfer',
        public bool $dispatchEvents = true,
        public array $metadata = [],
    ) {
        if (trim($this->beneficiaryName) === '') {
            throw new \InvalidArgumentException('Bank transfer beneficiary name cannot be empty.');
        }

        if (trim($this->iban) === '') {
            throw new \InvalidArgumentException('Bank transfer IBAN cannot be empty.');
        }

        if (trim($this->providerCode) === '') {
            throw new \InvalidArgumentException('Bank transfer provider code cannot be empty.');
        }

        if ($this->expiresAfterDays !== null && $this->expiresAfterDays < 1) {
            throw new \InvalidArgumentException('Bank transfer expiration must be at least one day when provided.');
        }
    }
}
