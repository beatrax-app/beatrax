<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Parsers\Banking;

use Modules\Import\Public\Contracts\PaymentTypeHinter;
use Modules\Import\Public\Dto\PaymentTypeHint;
use Modules\Import\Public\Enums\PaymentType;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

/**
 * @link ../../../../../.docs/features/import/architecture.md#payment-type-hinters
 */
final class Mt940PaymentTypeHinter implements PaymentTypeHinter
{
    private const SOURCE_FORMAT = 'mt940';

    // Mirrors AsnCsvPaymentTypeHinter::KEYWORDS verbatim — the MT940
    // :86: narrative emits the same lexemes as the CSV description.
    /**
     * @var list<array{keyword: string, type: PaymentType, confidence: int}>
     */
    private const KEYWORDS = [
        ['keyword' => 'betaalautomaat', 'type' => PaymentType::Pin, 'confidence' => 90],
        ['keyword' => 'geldautomaat', 'type' => PaymentType::Pin, 'confidence' => 90],
        ['keyword' => 'automatische incasso', 'type' => PaymentType::DirectDebit, 'confidence' => 85],
        ['keyword' => 'sepa direct debit', 'type' => PaymentType::DirectDebit, 'confidence' => 85],
        ['keyword' => 'incasso', 'type' => PaymentType::DirectDebit, 'confidence' => 85],
        ['keyword' => 'ideal', 'type' => PaymentType::Online, 'confidence' => 80],
        ['keyword' => 'onlinebetaling', 'type' => PaymentType::Online, 'confidence' => 80],
        ['keyword' => 'online betaling', 'type' => PaymentType::Online, 'confidence' => 80],
        ['keyword' => 'sepa credit transfer', 'type' => PaymentType::Transfer, 'confidence' => 70],
        ['keyword' => 'overboeking', 'type' => PaymentType::Transfer, 'confidence' => 70],
    ];

    public function hint(CanonicalTransaction $tx, string $sourceFormat): ?PaymentTypeHint
    {
        if ($sourceFormat !== self::SOURCE_FORMAT) {
            return null;
        }

        if ($tx->description === null || $tx->description === '') {
            return null;
        }

        $haystack = mb_strtolower($tx->description);
        foreach (self::KEYWORDS as $entry) {
            if (mb_strpos($haystack, $entry['keyword']) !== false) {
                return new PaymentTypeHint(
                    type: $entry['type'],
                    confidence: $entry['confidence'],
                    sourceHint: $entry['keyword'],
                );
            }
        }

        return null;
    }
}
