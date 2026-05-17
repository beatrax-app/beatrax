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
 *  - `unmatched()` — no matcher claimed responsibility for the message.
 *    This is the registry's terminal default, never returned by an
 *    individual matcher. The consumer transitions the row to
 *    `status='unmatched'` so a future matcher addition can re-process
 *    it.
 */
final class MatchOutcomeDto extends Data
{
    public function __construct(
        public readonly string $kind,
        public readonly ?ParsedReceiptDto $parsed,
        public readonly ?string $skipReason,
    ) {}

    public static function parsed(ParsedReceiptDto $receipt): self
    {
        return new self(kind: 'parsed', parsed: $receipt, skipReason: null);
    }

    public static function skipped(string $reason): self
    {
        return new self(kind: 'skipped', parsed: null, skipReason: $reason);
    }

    public static function unmatched(): self
    {
        return new self(kind: 'unmatched', parsed: null, skipReason: null);
    }
}
