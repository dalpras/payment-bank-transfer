<?php declare(strict_types=1);

namespace DalPraS\Payment\BankTransfer\Tests;

use DalPraS\Payment\BankTransfer\Config\BankTransferConfig;
use PHPUnit\Framework\TestCase;

final class BankTransferConfigTest extends TestCase
{
    public function testItRejectsEmptyIban(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new BankTransferConfig(
            beneficiaryName: 'Acme Srl',
            iban: '',
        );
    }
}
