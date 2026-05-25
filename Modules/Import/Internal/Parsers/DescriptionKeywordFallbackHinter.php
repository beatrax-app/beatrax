<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Parsers;

use Modules\Import\Public\Contracts\PaymentTypeHinter;
use Modules\Import\Public\Dto\PaymentTypeHint;
use Modules\Import\Public\Enums\PaymentType;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

/**
 * Universal last-resort `PaymentType` hinter. Runs against every
 * canonical row regardless of source format and inspects the
 * description for the Dutch / English payment-type lexemes the
 * source-specific hinters key off.
 *
 * The fallback exists so a future ingestion path (Google Play
 * receipt, EmailScan match, a bank export the adapter set doesn't
 * yet cover) still produces a non-Unknown chip whenever the
 * description carries a recognisable lexeme. Confidence is fixed at
 * 40 — below every source-specific hinter — so the fallback only
 * wins when no source-specific hinter returned a verdict.
 *
 * Returns `null` when the description is missing OR carries none of
 * the recognised lexemes; the classifier then falls back to
 * `PaymentType::Unknown`.
 *
 * Registration order matters for tie-breaking: this hinter MUST be
 * the LAST entry in `ImportServiceProvider::PAYMENT_TYPE_HINTER_FQNS`
 * so the registry test's "fallback is last" invariant holds.
 *
 * Pure / stateless / singleton-safe — no constructor dependencies.
 */
final class DescriptionKeywordFallbackHinter implements PaymentTypeHinter
{
    private const CONFIDENCE = 40;

    /**
     * Casefold-lowercase keyword → `PaymentType` mapping. Specific
     * lexemes (`automatische incasso`) precede their shorter
     * super-strings (`incasso`) so the longer match wins when both
     * lexemes appear in the same description.
     *
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
