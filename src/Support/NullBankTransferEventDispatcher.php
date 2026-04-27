<?php declare(strict_types=1);

namespace DalPraS\Payment\BankTransfer\Support;

use DalPraS\Payment\BankTransfer\Contract\BankTransferEventDispatcherInterface;
use DalPraS\Payment\BankTransfer\Dto\BankTransferEvent;

final class NullBankTransferEventDispatcher implements BankTransferEventDispatcherInterface
{
    public function dispatch(BankTransferEvent $event): void
    {
        // Intentionally empty: applications may plug in email, queue, ERP or outbox dispatchers.
    }
}
