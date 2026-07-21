<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Pipeline;

/**
 * @link ../../../../.docs/features/migration/architecture.md
 */
final class ConflictValueCodec
{
    /** @var list<string> Fields whose stored value is an integer minor-unit amount. */
    private const INT_FIELDS = ['budgeted_minor', 'amount_minor'];

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
        // A NULL/missing stored value degrades to the type's zero value (0 /
        // '') rather than null, since every call site immediately hands this
        // to a writer that expects a concrete scalar.
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
