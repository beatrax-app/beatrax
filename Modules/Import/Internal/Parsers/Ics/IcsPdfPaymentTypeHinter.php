<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Parsers\Ics;

use Modules\Import\Public\Contracts\PaymentTypeHinter;
use Modules\Import\Public\Dto\PaymentTypeHint;
use Modules\Import\Public\Enums\PaymentType;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

/**
 * Payment-type hinter specialised for Mijn ICS consumer-portal monthly
 * statement PDFs (`source_format = 'ics-pdf'`).
 *
 * The Mijn ICS PDF carries no per-row transaction-type column — every
 * ICS card row is functionally an online or POS card payment, so the
 * hinter inspects the canonical row's `description` for the small set
 * of distinctive Dutch CAPS tokens the ICS layout actually emits:
 *
 *  - `KOSTEN KASOPNAME` (cash-withdrawal fee row) → Fee
 *  - `GELDMAAT` (ATM withdrawal merchant) → Pin
 *  - `IDEAL BETALING` (iDEAL payment confirmation row) → Online
 *  - `INCASSO` (direct-debit settlement row) → DirectDebit
 *
 * Rows whose description matches none of the tokens above represent
 * online merchant charges (PayPal, Amazon, Anthropic, etc.) — the
 * hinter returns the lowest source-specific confidence (`PaymentType::Online`
 * confidence 60) so the description-keyword fallback's verdict is
 * preferred whenever it actually fires, and Unknown is the residual.
 *
 * The token match is case-insensitive (`mb_strpos` after
 * `mb_strtolower`) so future ICS layout-engine changes that re-case
 * the column text continue to resolve identically.
 *
 * Returns `null` when the row did not originate from an ICS PDF
 * import.
 *
 * Pure / stateless / singleton-safe — no constructor dependencies.
 */
final class IcsPdfPaymentTypeHinter implements PaymentTypeHinter
{
    private const SOURCE_FORMAT = 'ics-pdf';

    /**
     * Casefold-lowercase keyword → (`PaymentType`, confidence) mapping
     * matching the Mijn ICS consumer-portal layout. The order is
     * deliberate: `kosten kasopname` precedes the bare `geldmaat`
     * token so the per-withdrawal fee row classifies as Fee rather
     * than Pin even though both lexemes appear nearby on the page.
     *
     * @var list<array{keyword: string, type: PaymentType, confidence: int}>
     */
    private const KEYWORDS = [
        ['keyword' => 'kosten kasopname', 'type' => PaymentType::Fee, 'confidence' => 90],
        ['keyword' => 'geldmaat', 'type' => PaymentType::Pin, 'confidence' => 90],
        ['keyword' => 'ideal betaling', 'type' => PaymentType::Online, 'confidence' => 90],
        ['keyword' => 'incasso', 'type' => PaymentType::DirectDebit, 'confidence' => 85],
    ];

    /**
     * Default verdict when no token matches — every other ICS card row
     * is an online card-not-present charge. The low confidence (60)
     * keeps the description-keyword fallback's verdict competitive
     * when it does fire.
     */
    private const DEFAULT_TYPE = PaymentType::Online;

    private const DEFAULT_CONFIDENCE = 60;

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

        return new PaymentTypeHint(
            type: self::DEFAULT_TYPE,
            confidence: self::DEFAULT_CONFIDENCE,
            sourceHint: 'ics-card-default',
        );
    }
}
