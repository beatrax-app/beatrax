<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Parsers;

use Modules\Import\Public\Enums\PaymentType;

// One table for every hinter that reads Dutch bank narrative. The scan returns
// on the first hit, so a shorter lexeme above a longer one shadows it:
// `automatische incasso` must precede `incasso`.
final class DutchNarrativeKeywords
{
    // A null confidence marks a lexeme only the format-agnostic fallback
    // scans: no per-format hinter has ever scored these five, and inheriting
    // them at a guessed number would reclassify statement rows.
    /**
     * @var list<array{keyword: string, type: PaymentType, confidence: int|null}>
     */
    private const array TABLE = [
        ['keyword' => 'betaalautomaat', 'type' => PaymentType::Pin, 'confidence' => 90],
        ['keyword' => 'geldautomaat', 'type' => PaymentType::Pin, 'confidence' => 90],
        ['keyword' => 'geldmaat', 'type' => PaymentType::Pin, 'confidence' => null],
        ['keyword' => 'automatische incasso', 'type' => PaymentType::DirectDebit, 'confidence' => 85],
        ['keyword' => 'sepa direct debit', 'type' => PaymentType::DirectDebit, 'confidence' => 85],
        ['keyword' => 'direct debit', 'type' => PaymentType::DirectDebit, 'confidence' => null],
        ['keyword' => 'incasso', 'type' => PaymentType::DirectDebit, 'confidence' => 85],
        ['keyword' => 'ideal', 'type' => PaymentType::Online, 'confidence' => 80],
        ['keyword' => 'onlinebetaling', 'type' => PaymentType::Online, 'confidence' => 80],
        ['keyword' => 'online betaling', 'type' => PaymentType::Online, 'confidence' => 80],
        ['keyword' => 'sepa credit transfer', 'type' => PaymentType::Transfer, 'confidence' => 70],
        ['keyword' => 'credit transfer', 'type' => PaymentType::Transfer, 'confidence' => null],
        ['keyword' => 'overboeking', 'type' => PaymentType::Transfer, 'confidence' => 70],
        ['keyword' => 'refund', 'type' => PaymentType::Refund, 'confidence' => null],
        ['keyword' => 'fee', 'type' => PaymentType::Fee, 'confidence' => null],
    ];

    /**
     * @return list<array{keyword: string, type: PaymentType, confidence: int}>
     */
    public static function scored(): array
    {
        $scored = [];
        foreach (self::TABLE as $entry) {
            if ($entry['confidence'] === null) {
                continue;
            }
            $scored[] = [
                'keyword' => $entry['keyword'],
                'type' => $entry['type'],
                'confidence' => $entry['confidence'],
            ];
        }

        return $scored;
    }

    /**
     * @return list<array{keyword: string, type: PaymentType, confidence: int}>
     */
    public static function atConfidence(int $confidence): array
    {
        return array_map(
            static fn (array $entry): array => [
                'keyword' => $entry['keyword'],
                'type' => $entry['type'],
                'confidence' => $confidence,
            ],
            self::TABLE,
        );
    }
}
