<?php declare(strict_types=1);

namespace DalPraS\Payment\BankTransfer\Contract;

use DalPraS\Payment\Dto\CheckoutRequest;

interface BankTransferReferenceGeneratorInterface
{
    public function generate(CheckoutRequest $request): string;
}
