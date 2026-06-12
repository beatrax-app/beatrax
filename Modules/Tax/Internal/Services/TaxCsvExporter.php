<?php

declare(strict_types=1);

namespace Modules\Tax\Internal\Services;

use League\Csv\Writer;
use Modules\Core\Models\User;

/**
 * Generates the D-15 audit-extra CSV for a user's tax year.
 *
 * All 16 columns from the D-15 spec are emitted in documented order.
 * Money values are formatted as 2-decimal strings from minor-unit integers
 * (presentation-layer string formatting — not arithmetic; see PATTERNS.md).
 *
 * Data is read from TaxYearQuery so the CSV and the on-screen cockpit share
 * the same COALESCE year-override resolution and can never diverge.
 *
 * T-07-10: reads only TaxYearQuery::forUser($user->id, $year), which is
 * user-scoped — export can never leak another user's data.
 */
final class TaxCsvExporter
{
    public function __construct(
        private readonly TaxYearQuery $query,
    ) {}

    /**
     * Build and return the full CSV body as a string.
     *
     * Returns a header-only CSV when no tagged transactions exist for
     * the given year (safe, non-error behaviour).
     */
    public function export(User $user, int $year): string
    {
        $writer = Writer::createFromString();

        // D-15 column order — exact, tested.
        $writer->insertOne([
            'tax_year',
            'booked_date',
            'account',
            'counterparty',
            'counterparty_iban',
            'description',
            'deduction_category',
            'note',
            'settled_eur_amount',
            'original_amount',
            'original_currency',
            'transaction_type',
            'transaction_id',
            'source_format',
            'import_run_id',
            'fingerprint',
        ]);

        $data = $this->query->forUser($user->id, $year);

        foreach ($data->categories as $category) {
            /** @var array<string, mixed> $category */
            $rows = $category['rows'];
            if (! is_array($rows)) {
                continue;
            }
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                /** @var array<string, mixed> $row */
                $writer->insertOne($this->rowToColumns($row, $year));
            }
        }

        return $writer->toString();
    }

    /**
     * Map a TaxYearQuery row array to the 16 D-15 CSV columns.
     *
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function rowToColumns(array $row, int $year): array
    {
        // Use effective tax year: either the override or the queried year.
        $rawOverride = $row['taxYearOverride'] ?? null;
        $taxYear = is_numeric($rawOverride) ? (int) $rawOverride : $year;

        // booked_date: extract just the date part from the datetime string.
        $rawBookedAt = $row['bookedAt'] ?? '';
        $bookedAt = is_string($rawBookedAt) ? $rawBookedAt : '';
        $bookedDate = $bookedAt !== '' ? substr($bookedAt, 0, 10) : '';

        // Presentation-layer money formatting: minor units → 2-decimal string.
        // number_format is allowed here (PATTERNS.md: "acceptable in the export
        // presentation layer"). Never used for arithmetic.
        $rawSettled = $row['settledAmountMinor'] ?? 0;
        $settledMinor = is_numeric($rawSettled) ? (int) $rawSettled : 0;

        $rawOriginal = $row['amountMinor'] ?? 0;
        $originalMinor = is_numeric($rawOriginal) ? (int) $rawOriginal : 0;

        $settledEurAmount = number_format(abs($settledMinor) / 100, 2, '.', '');
        $originalAmount = number_format(abs($originalMinor) / 100, 2, '.', '');

        return [
            (string) $taxYear,
            $bookedDate,
            self::str($row['accountName'] ?? null),
            self::str($row['counterpartyName'] ?? null),
            self::str($row['counterpartyIban'] ?? null),
            self::str($row['description'] ?? null),
            self::str($row['categoryName'] ?? null),
            self::str($row['note'] ?? null),
            $settledEurAmount,
            $originalAmount,
            self::str($row['currency'] ?? null),
            self::str($row['transactionType'] ?? null),
            self::str($row['transactionId'] ?? null),
            self::str($row['sourceFormat'] ?? null),
            self::str($row['importRunId'] ?? null),
            self::str($row['fingerprint'] ?? null),
        ];
    }

    /**
     * Safely convert a mixed value to a string, returning '' for null/non-scalar.
     */
    private static function str(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_string($value)) {
            return $value;
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return '';
    }
}
