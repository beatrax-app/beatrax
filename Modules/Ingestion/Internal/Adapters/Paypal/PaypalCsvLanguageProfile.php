<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Paypal;

use Modules\Ingestion\Public\Enums\SourceFormat;

final readonly class PaypalCsvLanguageProfile
{
    public const FORMAT = SourceFormat::PaypalCsv->value;

    public const string DELIMITER = ',';

    public const bool HAS_HEADER = true;

    public const string SOURCE_ENCODING = 'UTF-8';

    // Detection passes when every token in a locale's list is present (order-insensitive).
    // "Reference Txn ID" is never localised, so it discriminates against a non-PayPal
    // CSV that happens to ship a "Datum" column. "Bruto" is here because a file
    // without it read every payment as zero and reported a clean import.
    /**
     * @var array<string, list<string>>
     */
    private const array LANGUAGE_SIGNATURES = [
        'nl' => [
            'Datum',
            'Tijd',
            'Tijdzone',
            'Omschrijving',
            'Valuta',
            'Bruto',
            'Transactiereferentie',
            'Reference Txn ID',
        ],
    ];

    public function __construct(
        private string $language,
    ) {}

    public function detected(): string
    {
        return $this->language;
    }

    /**
     * @param  list<string>  $columns  the header row as a list of cells
     */
    public static function detect(array $columns): ?self
    {
        // Cells are trimmed (the NL export ships trailing spaces) but signature
        // tokens are not, so a mis-spelt token surfaces instead of matching.
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
