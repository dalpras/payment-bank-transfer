<?php declare(strict_types=1);

namespace DalPraS\Payment\BankTransfer\Support;

use ReflectionProperty;

final class BankTransferMoneyFormatter
{
    public function format(mixed $money): ?string
    {
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

        foreach (['amount', 'minorAmount', 'amountMinor', 'minorUnits'] as $property) {
            $value = $this->publicPropertyValue($money, $property);

            if (is_int($value)) {
                return number_format($value / 100, 2, '.', '');
            }

            if (is_string($value) || is_float($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    public function currency(mixed $money): ?string
    {
        foreach (['getCurrency', 'currency'] as $method) {
            if (is_object($money) && method_exists($money, $method)) {
                $value = $money->{$method}();

                if (is_string($value)) {
                    return $value;
                }

                if (is_object($value)) {
                    $enumValue = $this->publicPropertyValue($value, 'value');

                    if (is_string($enumValue)) {
                        return $enumValue;
                    }
                }
            }
        }

        foreach (['currency', 'currencyCode'] as $property) {
            $value = $this->publicPropertyValue($money, $property);

            if (is_string($value)) {
                return $value;
            }

            if (is_object($value)) {
                $enumValue = $this->publicPropertyValue($value, 'value');

                if (is_string($enumValue)) {
                    return $enumValue;
                }
            }
        }

        return null;
    }

    private function publicPropertyValue(mixed $object, string $property): mixed
    {
        if (!is_object($object) || !property_exists($object, $property)) {
            return null;
        }

        $reflection = new ReflectionProperty($object, $property);

        if (!$reflection->isPublic()) {
            return null;
        }

        return $object->{$property};
    }
}