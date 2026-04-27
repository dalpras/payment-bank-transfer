<?php declare(strict_types=1);

namespace DalPraS\Payment\BankTransfer\Support;

final class BankTransferMoneyFormatter
{
    public function format(mixed $money): ?string
    {
        foreach (['amount', 'minorAmount', 'amountMinor', 'minorUnits'] as $property) {
            if (is_object($money) && property_exists($money, $property) && is_int($money->{$property})) {
                return number_format($money->{$property} / 100, 2, '.', '');
            }
        }

        foreach (['getAmount', 'getMinorAmount', 'amount', 'minorAmount'] as $method) {
            if (is_object($money) && method_exists($money, $method)) {
                $value = $money->{$method}();
                if (is_int($value)) {
                    return number_format($value / 100, 2, '.', '');
                }
                if (is_string($value) || is_float($value)) {
                    return (string) $value;
                }
            }
        }

        return null;
    }

    public function currency(mixed $money): ?string
    {
        foreach (['currency', 'currencyCode'] as $property) {
            if (is_object($money) && property_exists($money, $property)) {
                $value = $money->{$property};
                if (is_string($value)) {
                    return $value;
                }
                if (is_object($value) && property_exists($value, 'value') && is_string($value->value)) {
                    return $value->value;
                }
            }
        }

        foreach (['getCurrency', 'currency'] as $method) {
            if (is_object($money) && method_exists($money, $method)) {
                $value = $money->{$method}();
                if (is_string($value)) {
                    return $value;
                }
                if (is_object($value) && property_exists($value, 'value') && is_string($value->value)) {
                    return $value->value;
                }
            }
        }

        return null;
    }
}
