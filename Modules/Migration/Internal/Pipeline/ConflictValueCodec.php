<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Pipeline;

/**
 * Single stringify/parse boundary for a conflict's local/source/baseline
 * value triple (13.5-HUMAN-UAT.md Test 3c gap-fix). These values are
 * persisted as plain nullable `text` columns on `migration_staging_unmapped_items`
 * so `resolveConflict()`'s "take source" choice can be applied LATER, at
 * Confirm time, rather than needing to be applied (or discarded) the moment
 * a conflict is detected.
 *
 * `toStorage()`/`fromStorage()` intentionally do NOT go through
 * `CheckForUpdates`' human-display formatting (`(none)`/`true`/`false`
 * words) — those are lossy for round-tripping. This codec mirrors
 * `SourceMapWriter::baselineValueToString()`'s plain, round-trippable
 * convention instead, since these columns feed back into the SAME
 * apply/baseline-advance writers `SourceMapWriter` itself calls.
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

    /**
     * Parses a stored value back to the type the field's real writer
     * expects: an `int` for a minor-unit money field, a `string` otherwise.
     * A NULL/missing stored value degrades to the type's zero value (`0` /
     * `''`) rather than null, since every call site immediately hands this
     * to a writer that expects a concrete scalar.
     */
    public static function fromStorage(?string $stored, string $fieldName): string|int
    {
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
