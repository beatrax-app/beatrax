<?php

declare(strict_types=1);

namespace Modules\Import\Public\Dto;

use Modules\Import\Public\Enums\PaymentType;
use Spatie\LaravelData\Data;

/**
 * One hinter's verdict for a single canonical row. Returned by every
 * `PaymentTypeHinter::hint()` call that recognised the row.
 *
 * The classifier stage collects every non-null hint from every tagged
 * hinter, then picks the hint with the highest `confidence` value;
 * ties resolve by hinter registration order (earlier-registered wins).
 *
 * `confidence` is bounded to 0..100. Source-specific hinters that key
 * off authoritative source signals (CAMT.053 BkTxCd, PayPal event
 * type) emit confidence 90+; description-keyword matches against
 * Dutch / English payment-type vocabulary emit confidence 70-90; the
 * universal fallback hinter emits 40 so its verdict only wins when
 * every source-specific hinter declined.
 *
 * `sourceHint` is a developer-facing string (the matched keyword
 * token, the BkTxCd code, the PayPal event-type literal) used by the
 * classifier stage for structured-log lines only. It is never
 * surfaced in user-visible UI and never carries PII — only the
 * matched lexeme that triggered the hint.
 */
final class PaymentTypeHint extends Data
{
    public function __construct(
        public readonly PaymentType $type,
        public readonly int $confidence,
        public readonly string $sourceHint,
    ) {}
}
