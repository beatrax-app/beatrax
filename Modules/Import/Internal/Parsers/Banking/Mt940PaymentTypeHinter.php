<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Parsers\Banking;

use Modules\Import\Public\Contracts\PaymentTypeHinter;
use Modules\Import\Public\Dto\PaymentTypeHint;
use Modules\Import\Public\Enums\PaymentType;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

/**
 * Payment-type hinter for MT940 narrative exports
 * (`source_format = 'mt940'`).
 *
 * The MT940 Tag :86: narrative carries the same Dutch lexemes as the
 * the CSV description column — `Betaalautomaat` / `Geldautomaat` for
 * POS / ATM, `iDEAL` for online card-not-present, `Incasso` /
 * `SEPA Direct Debit` for direct debits, and `Overboeking` /
 * `SEPA Credit Transfer` for transfers. The keyword set is therefore
 * identical to the CSV hinter; only the source-format gate differs.
 *
 * Returns `null` when the row did not originate from an MT940 import
 * or when none of the lexemes appears in the description.
 *
 * Pure / stateless / singleton-safe — no constructor dependencies.
 */
final class Mt940PaymentTypeHinter implements PaymentTypeHinter
{
    private const SOURCE_FORMAT = 'mt940';

    /**
     * Casefold-lowercase keyword → (`PaymentType`, confidence) mapping.
     * Mirrors `AsnCsvPaymentTypeHinter::KEYWORDS` because the MT940
     * narrative emits the same lexemes a bank places in its CSV
     * description column.
     *
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
