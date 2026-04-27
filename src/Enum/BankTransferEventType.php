<?php declare(strict_types=1);

namespace DalPraS\Payment\BankTransfer\Enum;

enum BankTransferEventType: string
{
    case CheckoutCreated = 'checkout_created';
    case CustomerActionRequired = 'customer_action_required';
    case PaymentConfirmed = 'payment_confirmed';
    case PaymentCancelled = 'payment_cancelled';
    case RefundMarked = 'refund_marked';
    case PaymentSynced = 'payment_synced';
}
