<?php declare(strict_types=1);

namespace DalPraS\Payment\BankTransfer\Tests;

use DalPraS\Payment\BankTransfer\Dto\BankTransferInstructions;
use PHPUnit\Framework\TestCase;

final class BankTransferInstructionsTest extends TestCase
{
    public function testItRehydratesSerializedInstructions(): void
    {
        $serialized = [
            'beneficiaryName' => 'VIMAR S.p.A.',
            'iban' => 'IT62TEST',
            'bic' => 'BCITITMM',
            'bankName' => 'INTESA SANPAOLO',
            'bankAddress' => 'AG. BASSANO DEL GRAPPA (VI)',
            'reference' => 'pay-ref',
            'amount' => '1708.00',
            'currency' => 'EUR',
            'expiresAt' => '2026-09-16T10:00:00+02:00',
            'metadata' => ['participants' => 'test'],
        ];

        $instructions = BankTransferInstructions::fromArray($serialized);

        self::assertSame($serialized, $instructions->toArray());
    }
}
