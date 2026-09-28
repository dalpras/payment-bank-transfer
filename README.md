# dalpras/payment-bank-transfer

Manual bank transfer connector for `dalpras/payment-core`.

This package implements a provider for offline/manual bank transfers. It creates normalized checkout instructions containing beneficiary, IBAN/BIC, payment reference, amount and expiration. It does **not** contact a payment gateway, does **not** verify settlement automatically, and does **not** send emails directly.

Side effects such as email, ERP calls, CRM updates, outbox writes or queue messages are intentionally decoupled behind a small event dispatcher contract.

## Status

Skeleton package, consistent with the current `dalpras/payment-core` and provider-connector split.

Included:

- `BankTransferProvider` implementing `DalPraS\Payment\Contract\PaymentProviderInterface`
- `BankTransferConfig`
- bank transfer instructions DTO
- optional event dispatcher interface
- null dispatcher
- reference generator interface
- unsupported-operation exception
- minimal PHPUnit structure

Not included:

- SMTP, SendGrid, Mailgun, Symfony Mailer or Laminas Mail integrations
- ERP / CRM clients
- bank account reconciliation APIs
- automatic settlement confirmation
- framework controllers

## Installation

```bash
composer require dalpras/payment-bank-transfer
```

## Basic usage

```php
use DalPraS\Payment\BankTransfer\Config\BankTransferConfig;
use DalPraS\Payment\BankTransfer\Provider\BankTransferProvider;

$config = new BankTransferConfig(
    beneficiaryName: 'My Store Srl',
    iban: 'IT60X0542811101000000123456',
    bic: 'BCITITMM',
    bankName: 'Example Bank',
    expiresAfterDays: 7,
);

$provider = new BankTransferProvider($config);

$response = $provider->createCheckout($checkoutRequest);

// $response->redirectRequired === false
// $response->status === PaymentStatus::PendingCustomerAction
// $response->raw['instructions'] contains the bank transfer instructions
```

## Decoupled callbacks / side effects

The package exposes `BankTransferEventDispatcherInterface`. Your application can implement it to send email, enqueue jobs, call services, or write to an outbox.

```php
use DalPraS\Payment\BankTransfer\Contract\BankTransferEventDispatcherInterface;
use DalPraS\Payment\BankTransfer\Dto\BankTransferEvent;
use DalPraS\Payment\BankTransfer\Enum\BankTransferEventType;

final class AppBankTransferEventDispatcher implements BankTransferEventDispatcherInterface
{
    public function dispatch(BankTransferEvent $event): void
    {
        if ($event->type === BankTransferEventType::CustomerActionRequired) {
            // Create an email job, write to outbox, call ERP, etc.
            // Do not put provider-specific code into the package.
        }
    }
}
```

Then inject it:

```php
$provider = new BankTransferProvider(
    config: $config,
    eventDispatcher: new AppBankTransferEventDispatcher(),
);
```

For production, prefer an outbox implementation so side effects happen after your application has persisted the payment state.

## Core mapping

### `createCheckout()`

Creates manual bank transfer instructions and returns:

- `PaymentStatus::PendingCustomerAction`
- `redirectRequired = false`
- no redirect URL
- `providerPaymentId` equal to the generated bank transfer reference
- raw payload containing `instructions`

### `completeCheckout()`

No external provider completion exists. The payment remains pending until the merchant confirms settlement.

### `authorize()`

Unsupported. Manual bank transfer cannot authorize funds.

### `capture()`

Used as the manual “mark paid / confirmed” operation. Returns `PaymentStatus::Captured` by default.

### `cancel()`

Marks the manual bank transfer as cancelled.

### `refund()`

Marks the refund as manually processed. No bank API call is made.

### `sync()`

Returns the manual state requested through metadata, or `pending_customer_action` by default.

### Webhooks

Manual bank transfer has no provider webhook. `parseWebhook()` returns an unsupported event, and `verifyWebhook()` returns an unverified result.

## Events

The provider can dispatch:

- `checkout_created`
- `customer_action_required`
- `payment_confirmed`
- `payment_cancelled`
- `refund_marked`
- `payment_synced`

Events are neutral DTOs. They do not depend on mailers, queues, frameworks, ERPs, or CRMs.

## Recommended production pattern

For robust side effects:

1. call `PaymentManager` / provider operation
2. persist payment + operation result
3. write a payment event to an outbox
4. process the outbox asynchronously in your application
5. send email / invoke external services from the application layer

This keeps the connector reusable and provider-focused.

## Package layout

- `src/Config/BankTransferConfig.php`
- `src/Provider/BankTransferProvider.php`
- `src/Dto/BankTransferInstructions.php`
- `src/Dto/BankTransferEvent.php`
- `src/Contract/BankTransferEventDispatcherInterface.php`
- `src/Contract/BankTransferReferenceGeneratorInterface.php`
- `src/Support/*`
- `src/Exception/*`

## License

MIT

## Compatibility with payment-core metadata persistence

This package is compatible with the payment-core metadata lifecycle introduced for
redirect and multi-step providers.

Even though a manual bank transfer has no external gateway operation id, the
provider still returns normalized metadata so `PaymentManager` can persist and
reuse the payment reference consistently across requests.

### Metadata returned by `createCheckout()`

`createCheckout()` returns `CheckoutResponse::$metadata` with generic keys used by
core and bank-transfer-specific keys used by applications:

```php
[
    'provider' => 'bank_transfer',
    'provider_payment_id' => 'PAYMENT-REFERENCE',
    'order_id' => 'MERCHANT-REFERENCE',
    'payment_reference' => 'PAYMENT-REFERENCE',
    'manual' => true,
    'bank_transfer_instructions' => [
        'beneficiaryName' => 'My Store Srl',
        'iban' => 'IT...',
        'bic' => '...',
        'bankName' => 'My Bank',
        'bankAddress' => 'Main Branch',
        'reference' => 'PAYMENT-REFERENCE',
        'amount' => '100.00',
        'currency' => 'EUR',
        'expiresAt' => '2026-01-01T12:00:00+00:00',
    ],
    'bank_transfer_reference' => 'PAYMENT-REFERENCE',
    'bank_transfer_iban' => 'IT...',
    'bank_transfer_bic' => '...',
    'bank_transfer_beneficiary_name' => 'My Store Srl',
    'bank_transfer_amount' => '100.00',
    'bank_transfer_currency' => 'EUR',
    'bank_transfer_expires_at' => '2026-01-01T12:00:00+00:00',
]
```

Applications that persist provider metadata on their order entity should merge
this metadata after checkout creation/completion, just like they do for Nexi and
PayPal. New application code should prefer the serialized
`bank_transfer_instructions` snapshot and rehydrate it with
`BankTransferInstructions::fromArray()`. The flattened `bank_transfer_*` keys are
kept for backward compatibility.

### Completion

`completeCheckout()` keeps the payment in `PendingCustomerAction`. There is no
external provider to verify. The merchant must later confirm settlement manually,
usually by calling `capture()` through `PaymentManager`.

### Manual confirmation

Use `capture()` to mark the bank transfer as paid:

```php
$result = $paymentManager->capture(new CaptureRequest(
    providerCode: 'bank_transfer',
    paymentReference: $paymentReference,
    providerPaymentId: null,
    idempotencyKey: $paymentReference . ':bank-transfer-confirm',
    metadata: [
        'description' => 'Bank transfer received',
    ],
));
```

If the payment repository is backed by Redis/Symfony Cache, `PaymentManager` will
reuse the stored `provider_payment_id` / `bank_transfer_reference` automatically.

### Manual cancellation

Use `cancel()` to mark the pending transfer as cancelled:

```php
$result = $paymentManager->cancel(new CancelRequest(
    providerCode: 'bank_transfer',
    paymentReference: $paymentReference,
    providerPaymentId: null,
    idempotencyKey: $paymentReference . ':bank-transfer-cancel',
    metadata: [
        'description' => 'Customer cancelled before transfer was received',
    ],
));
```

### Manual refund

Use `refund()` to mark an already captured transfer as manually refunded:

```php
$result = $paymentManager->refund(new RefundRequest(
    providerCode: 'bank_transfer',
    paymentReference: $paymentReference,
    providerPaymentId: null,
    idempotencyKey: $paymentReference . ':bank-transfer-refund:' . $refundId,
    metadata: [
        'amount_minor' => '5000',
        'currency' => 'EUR',
        'description' => 'Manual refund executed by accounting',
    ],
));
```

### Persistence recommendation

Use the same production setup as Nexi and PayPal:

- `PaymentRepositoryInterface` wired to `CachePaymentRepository` or `RedisPaymentRepository`
- `IdempotencyStoreInterface` wired to `CacheIdempotencyStore` or `RedisIdempotencyStore`
- durable order-level metadata persisted in your application entity, for example
  `OrderEntity::paymentMetadata`

The cache/Redis repository is temporary flow state. Your order table remains the
long-term source for accounting, customer service, refunds and debugging.
