<?php declare(strict_types=1);

namespace DalPraS\Payment\BankTransfer\Tests;

use DalPraS\Payment\BankTransfer\Config\BankTransferConfig;
use DalPraS\Payment\BankTransfer\Provider\BankTransferProvider;
use DalPraS\Payment\Dto\CancelRequest;
use DalPraS\Payment\Dto\CheckoutRequest;
use DalPraS\Payment\Dto\SyncRequest;
use DalPraS\Payment\Enum\Currency;
use DalPraS\Payment\Enum\PaymentStatus;
use DalPraS\Payment\ValueObject\AmountBreakdown;
use DalPraS\Payment\ValueObject\Customer;
use DalPraS\Payment\ValueObject\Money;
use PHPUnit\Framework\TestCase;

final class BankTransferProviderTest extends TestCase
{
    public function testCheckoutMetadataContainsSerializedInstructions(): void
    {
        $money = Money::fromDecimal('1708.00', Currency::EUR);
        $zero = Money::zero(Currency::EUR);
        $request = new CheckoutRequest(
            providerCode: 'bank_transfer',
            paymentReference: 'pay-ref',
            merchantReference: '8012',
            customer: new Customer(email: 'customer@example.com'),
            items: [],
            amounts: new AmountBreakdown($money, $zero, $zero, $zero, $money),
            returnUrl: 'https://example.test/return',
            cancelUrl: 'https://example.test/cancel',
        );
        $provider = new BankTransferProvider(new BankTransferConfig(
            beneficiaryName: 'VIMAR S.p.A.',
            iban: 'IT62TEST',
            bankName: 'INTESA SANPAOLO',
            bankAddress: 'AG. BASSANO DEL GRAPPA (VI)',
        ));

        $response = $provider->createCheckout($request);
        $serialized = $response->metadata['bank_transfer_instructions'] ?? null;

        self::assertIsArray($serialized);
        self::assertSame('VIMAR S.p.A.', $serialized['beneficiaryName']);
        self::assertSame('IT62TEST', $serialized['iban']);
        self::assertSame('1708.00', $serialized['amount']);
        self::assertSame('EUR', $serialized['currency']);
        self::assertSame('pay-ref', $serialized['reference']);
    }

    public function testSyncRepairsUnknownManualTransferToPending(): void
    {
        $provider = new BankTransferProvider(new BankTransferConfig(
            beneficiaryName: 'VIMAR S.p.A.',
            iban: 'IT62TEST',
        ));

        $result = $provider->sync(new SyncRequest(
            providerCode: 'bank_transfer',
            paymentReference: 'payment-ref',
            providerPaymentId: 'event-order-8082',
            metadata: ['status' => PaymentStatus::Unknown->value],
        ));

        self::assertSame(PaymentStatus::PendingCustomerAction, $result->status);
        self::assertSame(PaymentStatus::PendingCustomerAction->value, $result->metadata['status']);
    }

    public function testSyncRepairsConfirmedUnknownManualTransferToCaptured(): void
    {
        $provider = new BankTransferProvider(new BankTransferConfig(
            beneficiaryName: 'VIMAR S.p.A.',
            iban: 'IT62TEST',
        ));

        $result = $provider->sync(new SyncRequest(
            providerCode: 'bank_transfer',
            paymentReference: 'payment-ref',
            providerPaymentId: 'event-order-8082',
            metadata: [
                'status' => PaymentStatus::Unknown->value,
                'bank_transfer_confirmed_at' => '2026-09-17T12:00:00+00:00',
            ],
        ));

        self::assertSame(PaymentStatus::Captured, $result->status);
        self::assertSame(PaymentStatus::Captured->value, $result->metadata['status']);
    }

    public function testCancelMarksManualTransferCancelled(): void
    {
        $provider = new BankTransferProvider(new BankTransferConfig(
            beneficiaryName: 'VIMAR S.p.A.',
            iban: 'IT62TEST',
        ));

        $result = $provider->cancel(new CancelRequest(
            providerCode: 'bank_transfer',
            paymentReference: 'payment-ref',
            providerPaymentId: 'event-order-8082',
            metadata: [
                'status' => PaymentStatus::Cancelled->value,
                'rejected_by' => 'backoffice',
            ],
        ));

        self::assertSame(PaymentStatus::Cancelled, $result->status);
        self::assertSame('event-order-8082', $result->providerPaymentId);
        self::assertSame(PaymentStatus::Cancelled->value, $result->metadata['status']);
        self::assertSame('backoffice', $result->metadata['rejected_by']);
        self::assertArrayHasKey('bank_transfer_cancelled_at', $result->metadata);
    }

}
