<?php

declare(strict_types=1);

namespace Modules\Receipts\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * Sum-type wrapper returned by `SenderMatcher::match()`.
 *
 * Three constructor branches:
 *
 *  - `parsed(ParsedReceiptDto $r)` — body parsed successfully into a
 *    canonical-receipt shape. The downstream `RecordReceipt` action
 *    converts the DTO and feeds the standard SourceAdapter pipeline.
 *
 *  - `skipped(string $reason)` — `canHandle()` claimed responsibility
 *    but the body is not a transaction (login notice, password reset,
 *    promotional email leaking through a matched sender domain). The
 *    consumer transitions the source row to `status='skipped'` with
 *    the reason archived for later audit.
 *
 *  - `unmatched(?string $reason = null)` — either no matcher claimed
 *    responsibility for the message (registry default; reason null) or
 *    an individual matcher recognised the sender but encountered a
 *    row-level extraction failure that should NOT throw upward (e.g.
 *    an unparseable Date header — `reason='invalid_date_header'`). The
 *    consumer transitions the row to `status='unmatched'` so a future
 *    matcher addition or a manual triage step can re-process it.
 */
final class MatchOutcomeDto extends Data
{
    public function __construct(
        public readonly string $kind,
        public readonly ?ParsedReceiptDto $parsed,
        public readonly ?string $skipReason,
        public readonly ?string $unmatchedReason = null,
    ) {}

    public static function parsed(ParsedReceiptDto $receipt): self
    {
        return new self(kind: 'parsed', parsed: $receipt, skipReason: null);
    }

    public static function skipped(string $reason): self
    {
        return new self(kind: 'skipped', parsed: null, skipReason: $reason);
    }

    public static function unmatched(?string $reason = null): self
    {
        return new self(kind: 'unmatched', parsed: null, skipReason: null, unmatchedReason: $reason);
    }
}
