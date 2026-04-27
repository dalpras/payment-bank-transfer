<?php declare(strict_types=1);

namespace DalPraS\Payment\BankTransfer\Support;

use DalPraS\Payment\BankTransfer\Contract\BankTransferReferenceGeneratorInterface;
use DalPraS\Payment\Dto\CheckoutRequest;

final class PaymentReferenceBankTransferReferenceGenerator implements BankTransferReferenceGeneratorInterface
{
    public function generate(CheckoutRequest $request): string
    {
        return $request->paymentReference;
    }
}
