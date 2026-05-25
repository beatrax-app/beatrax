<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Parsers\Asn;

use Modules\Import\Public\Contracts\PaymentTypeHinter;
use Modules\Import\Public\Dto\PaymentTypeHint;
use Modules\Import\Public\Enums\PaymentType;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

/**
 * Payment-type hinter specialised for ASN bank CSV exports
 * (`source_format = 'asn-csv'`).
 *
 * Inspects the canonical row's `description` for the Dutch lexemes ASN
 * embeds in every CSV row's description column: `Betaalautomaat` for
 * physical POS terminals, `Geldautomaat` for ATM withdrawals, `iDEAL`
 * and `Online betaling` for online card-not-present payments, `Incasso`
 * and `Automatische incasso` for direct debits, and `Overboeking` /
 * `SEPA Credit Transfer` for transfers. Each lexeme matches via
 * `mb_strpos` against the casefold description so accent + case
 * variants from different ASN export generations resolve identically.
 *
 * Returns `null` when the row did not originate from an ASN CSV import
 * or when none of the lexemes appears in the description. A non-null
 * `PaymentTypeHint` carries the matched keyword (lowercased) as
 * `sourceHint` so the classifier's structured log surfaces which
 * lexeme triggered the verdict.
 *
 * Pure / stateless / singleton-safe — no constructor dependencies.
 */
final class AsnCsvPaymentTypeHinter implements PaymentTypeHinter
{
    private const SOURCE_FORMAT = 'asn-csv';

    /**
     * Casefold-lowercase keyword → (`PaymentType`, confidence) mapping.
     * The order is deliberate: more specific lexemes appear first so
     * `automatische incasso` wins over `incasso` when both match.
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
