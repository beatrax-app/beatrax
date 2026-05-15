<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Paypal;

/**
 * Language profile + locale-discriminator for PayPal "Activity Download"
 * CSV exports.
 *
 * PayPal localises both column headers and event-type values per the
 * account locale. This profile carries the empirical token vocabulary
 * for each supported locale. The `detect()` factory inspects the CSV's
 * header row and returns a profile instance carrying the matched
 * language code; the adapter uses the instance's `detected()` getter
 * when looking up event types in `PaypalCsvEventTypeMap`.
 *
 * Currently registered locales: `nl`. A second-language entry is
 * incremental work once a second-language sample is available.
 */
final class PaypalCsvLanguageProfile
{
    public const FORMAT = 'paypal-csv';

    public const DELIMITER = ',';

    public const HAS_HEADER = true;

    public const SOURCE_ENCODING = 'UTF-8';

    /**
     * Discriminator subset for each supported locale. Detection passes
     * when every token in the list is present in the CSV header row.
     * The intersection check is order-insensitive — PayPal does NOT
     * reorder columns across exports, but staying order-insensitive
     * future-proofs against minor PayPal export changes.
     *
     * The `Reference Txn ID` token is universally English (PayPal
     * never localised it) and is the strongest discriminator against
     * any non-PayPal CSV that happens to ship a `Datum` column —
     * neither ASN nor ICS exports carry it.
     *
     * @var array<string, list<string>>
     */
    private const LANGUAGE_SIGNATURES = [
        'nl' => [
            'Datum',
            'Tijd',
            'Tijdzone',
            'Omschrijving',
            'Valuta',
            'Transactiereferentie',
            'Reference Txn ID',
        ],
    ];

    public function __construct(
        private readonly string $language,
    ) {}

    public function detected(): string
    {
        return $this->language;
    }

    /**
     * Try every registered language signature against the CSV header
     * row. Returns the matched profile, or `null` if no signature
     * matches (the adapter then raises
     * `UnsupportedPaypalCsvLanguageException` carrying the
     * `supported()` list in the error message).
     *
     * @param  list<string>  $columns  the header row as a list of cells
     */
    public static function detect(array $columns): ?self
    {
        // Normalise header tokens before comparison: PayPal's NL export
        // ships `"Bruto "` / `"Kosten "` with a trailing space inside the
        // quoted cell, and the BOM-stripping reader may or may not have
        // trimmed those. Compare against trimmed cells but keep the
        // signature tokens un-trimmed to surface a mis-spelling early.
        $trimmedColumns = array_map(static fn (string $c): string => trim($c), $columns);

        foreach (self::LANGUAGE_SIGNATURES as $language => $required) {
            $missing = array_diff($required, $trimmedColumns);
            if ($missing === []) {
                return new self($language);
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function supported(): array
    {
        return array_keys(self::LANGUAGE_SIGNATURES);
    }
}
