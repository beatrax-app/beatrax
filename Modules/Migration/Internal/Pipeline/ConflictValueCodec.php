<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Pipeline;

final class ConflictValueCodec
{
    /** @var list<string> Fields whose stored value is an integer minor-unit amount. */
    private const array INT_FIELDS = ['budgeted_minor', 'amount_minor'];

    public static function toStorage(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? '1' : '0',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value),
        };
    }

    public static function fromStorage(?string $stored, string $fieldName): string|int
    {
        // A missing stored value degrades to the type's zero value, never null:
        // every call site hands the result straight to a writer expecting a scalar.
        if ($stored === null) {
            return self::isMoneyField($fieldName) ? 0 : '';
        }

        return self::isMoneyField($fieldName) ? (int) $stored : $stored;
    }

    public static function isMoneyField(string $fieldName): bool
    {
        return in_array($fieldName, self::INT_FIELDS, true);
    }
}
