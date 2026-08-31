<?php

declare(strict_types=1);

namespace Modules\Ingestion\Public\Csv;

/**
 * @link ../../../../.docs/features/ingestion/asn-description-delimiters.md
 */
final class AsnDescriptionDelimiters
{
    // Public because the backfill over already-imported rows has to take a
    // stored description apart on the exact string the adapter joined it with;
    // a second spelling would disagree on every row whose payment reference
    // and description both arrived wrapped.
    public const string SEPARATOR = ' / ';

    private const string DELIMITER = "'";

    // ASN wraps this field in apostrophes as a delimiter, not as punctuation.
    // Only a MATCHING pair goes, so "Bakkerij 't Stoepje" and an unbalanced
    // quote are punctuation and stay; rawPayload keeps the row exactly as the
    // bank wrote it, which is where the untouched form belongs.
    public static function unwrap(string $field): string
    {
        if (strlen($field) < 2
            || ! str_starts_with($field, self::DELIMITER)
            || ! str_ends_with($field, self::DELIMITER)) {
            return $field;
        }

        return trim(substr($field, 1, -1));
    }

    // The same rule over a description the ledger ALREADY holds joined, for
    // the rows imported before it existed. Null means the row should hold no
    // description at all, which is what the adapter yields when every part
    // unwraps away.
    public static function unwrapStored(string $stored): ?string
    {
        $kept = [];

        foreach (self::splitJoined($stored) as $part) {
            $unwrapped = self::unwrap($part);

            // Still a matching pair after one unwrap: the adapter strips one
            // pair, so stripping a second here would both disagree with it and
            // move the row again on a re-run. Leave the whole value alone.
            if (self::unwrap($unwrapped) !== $unwrapped) {
                return $stored;
            }

            if ($unwrapped !== '') {
                $kept[] = $unwrapped;
            }
        }

        return $kept === [] ? null : implode(self::SEPARATOR, $kept);
    }

    // Splits only where a separator touches a delimiter, which makes the split
    // lossless — imploding an unsplit value reproduces it byte for byte. A
    // separator inside one bank narrative ("NL-1234 / FEBRUARI 2026") is
    // therefore never mistaken for the join between two fields.
    /**
     * @return list<string>
     */
    private static function splitJoined(string $stored): array
    {
        $separator = preg_quote(self::SEPARATOR, '~');
        $delimiter = preg_quote(self::DELIMITER, '~');

        $parts = preg_split(
            "~{$separator}(?={$delimiter})|(?<={$delimiter}){$separator}~",
            $stored,
        );

        return is_array($parts) ? $parts : [$stored];
    }
}
