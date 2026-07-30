<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Parsers;

use Modules\Import\Public\Enums\PaymentType;

/**
 * @link ../../../../.docs/features/import/architecture.md#payment-type-hinters
 */
final class DescriptionKeywordFallbackHinter extends DescriptionKeywordHinter
{
    private const CONFIDENCE = 40;

    // Specific lexemes (`automatische incasso`) precede their shorter
    // super-strings (`incasso`) so the longer match wins when both
    // appear in the same description.
    /**
     * @var list<array{keyword: string, type: PaymentType}>
     */
    private const UNSCORED_KEYWORDS = [
        ['keyword' => 'betaalautomaat', 'type' => PaymentType::Pin],
        ['keyword' => 'geldautomaat', 'type' => PaymentType::Pin],
        ['keyword' => 'geldmaat', 'type' => PaymentType::Pin],
        ['keyword' => 'automatische incasso', 'type' => PaymentType::DirectDebit],
        ['keyword' => 'sepa direct debit', 'type' => PaymentType::DirectDebit],
        ['keyword' => 'direct debit', 'type' => PaymentType::DirectDebit],
        ['keyword' => 'incasso', 'type' => PaymentType::DirectDebit],
        ['keyword' => 'ideal', 'type' => PaymentType::Online],
        ['keyword' => 'onlinebetaling', 'type' => PaymentType::Online],
        ['keyword' => 'online betaling', 'type' => PaymentType::Online],
        ['keyword' => 'sepa credit transfer', 'type' => PaymentType::Transfer],
        ['keyword' => 'credit transfer', 'type' => PaymentType::Transfer],
        ['keyword' => 'overboeking', 'type' => PaymentType::Transfer],
        ['keyword' => 'refund', 'type' => PaymentType::Refund],
        ['keyword' => 'fee', 'type' => PaymentType::Fee],
    ];

    // Answers for every row regardless of origin: this is the last hinter in
    // the registry and exists to catch what the per-parser ones did not.
    protected function handles(string $sourceFormat): bool
    {
        unset($sourceFormat);

        return true;
    }

    // Every keyword here carries the same low confidence, so the table stores
    // the type alone and the confidence is stamped on at read time — keeping
    // one number to change rather than one per row.
    /**
     * @return list<array{keyword: string, type: PaymentType, confidence: int}>
     */
    protected function keywords(): array
    {
        return array_map(
            static fn (array $entry): array => [
                'keyword' => $entry['keyword'],
                'type' => $entry['type'],
                'confidence' => self::CONFIDENCE,
            ],
            self::UNSCORED_KEYWORDS,
        );
    }
}
