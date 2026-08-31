<?php

declare(strict_types=1);

namespace Modules\Tax\Internal\Services;

use League\Csv\EscapeFormula;
use League\Csv\Writer;
use Modules\Core\Models\User;
use Modules\Ledger\Public\ValueObjects\MoneyInput;

/**
 * @link ../../../../.docs/features/tax/tax-year-resolution.md
 */
final readonly class TaxCsvExporter
{
    public function __construct(
        private TaxYearQuery $query,
    ) {}

    public function export(User $user, int $year): string
    {
        $writer = Writer::createFromString();

        // Descriptions, counterparty names and notes are free text, and a
        // leading =/+/-/@ is a live formula in a spreadsheet.
        $writer->addFormatter(new EscapeFormula);

        // Header first, so a year with no tags still exports a valid CSV
        // rather than an empty file.
        $writer->insertOne([
            'tax_year',
            'posted_date',
            'account',
            'counterparty',
            'counterparty_iban',
            'description',
            'deduction_category',
            'note',
            'settled_amount',
            'settled_currency',
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
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function rowToColumns(array $row, int $year): array
    {
        $rawOverride = $row['taxYearOverride'] ?? null;
        $taxYear = is_numeric($rawOverride) ? (int) $rawOverride : $year;

        $rawPostedAt = $row['postedAt'] ?? '';
        $postedAt = is_string($rawPostedAt) ? $rawPostedAt : '';
        $postedDate = $postedAt !== '' ? substr($postedAt, 0, 10) : '';

        $rawSettled = $row['settledAmountMinor'] ?? 0;
        $settledMinor = is_numeric($rawSettled) ? (int) $rawSettled : 0;

        $rawOriginal = $row['amountMinor'] ?? 0;
        $originalMinor = is_numeric($rawOriginal) ? (int) $rawOriginal : 0;

        $settledAmount = MoneyInput::toDecimalString(abs($settledMinor), self::str($row['settledCurrency'] ?? null));
        $originalAmount = MoneyInput::toDecimalString(abs($originalMinor), self::str($row['currency'] ?? null));

        return [
            (string) $taxYear,
            $postedDate,
            self::str($row['accountName'] ?? null),
            self::str($row['counterpartyName'] ?? null),
            self::str($row['counterpartyIban'] ?? null),
            self::str($row['description'] ?? null),
            self::str($row['categoryName'] ?? null),
            self::str($row['note'] ?? null),
            $settledAmount,
            self::str($row['settledCurrency'] ?? null),
            $originalAmount,
            self::str($row['currency'] ?? null),
            self::str($row['transactionType'] ?? null),
            self::str($row['transactionId'] ?? null),
            self::str($row['sourceFormat'] ?? null),
            self::str($row['importRunId'] ?? null),
            self::str($row['fingerprint'] ?? null),
        ];
    }

    private static function str(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_scalar($value) => (string) $value,
            default => '',
        };
    }
}
