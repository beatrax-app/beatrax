<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Parsers;

use Modules\Import\Public\Contracts\PaymentTypeHinter;
use Modules\Import\Public\Dto\PaymentTypeHint;
use Modules\Import\Public\Enums\PaymentType;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

/**
 * @link ../../../../.docs/features/import/architecture.md#payment-type-hinters
 */
final class DescriptionKeywordFallbackHinter implements PaymentTypeHinter
{
    private const CONFIDENCE = 40;

    // Specific lexemes (`automatische incasso`) precede their shorter
    // super-strings (`incasso`) so the longer match wins when both
    // appear in the same description.
    /**
     * @var list<array{keyword: string, type: PaymentType}>
     */
    private const KEYWORDS = [
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

    public function hint(CanonicalTransaction $tx, string $sourceFormat): ?PaymentTypeHint
    {
        // The $sourceFormat gate is intentionally absent — the
        // fallback inspects every row, regardless of origin.
        unset($sourceFormat);

        if ($tx->description === null || $tx->description === '') {
            return null;
        }

        $haystack = mb_strtolower($tx->description);
        foreach (self::KEYWORDS as $entry) {
            if (mb_strpos($haystack, $entry['keyword']) !== false) {
                return new PaymentTypeHint(
                    type: $entry['type'],
                    confidence: self::CONFIDENCE,
                    sourceHint: $entry['keyword'],
                );
            }
        }

        return null;
    }
}
