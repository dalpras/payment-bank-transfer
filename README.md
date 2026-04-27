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
