<?php declare(strict_types=1);

namespace DalPraS\Payment\BankTransfer\Contract;

use DalPraS\Payment\BankTransfer\Dto\BankTransferEvent;

interface BankTransferEventDispatcherInterface
{
    public function dispatch(BankTransferEvent $event): void;
}
