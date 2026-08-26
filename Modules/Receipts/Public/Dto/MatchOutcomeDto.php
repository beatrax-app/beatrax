<?php

declare(strict_types=1);

namespace Modules\Receipts\Public\Dto;

use Modules\Receipts\Public\Enums\MatchOutcomeKind;
use Spatie\LaravelData\Data;

// Sum-type wrapper returned by SenderMatcher::match(): parsed() on
// success, skipped($reason) for a non-transaction body, unmatched()
// when no matcher claimed it or a row-level extraction failed without
// throwing.
final class MatchOutcomeDto extends Data
{
    public function __construct(
        public readonly MatchOutcomeKind $kind,
        public readonly ?ParsedReceiptDto $parsed,
        public readonly ?string $skipReason,
        public readonly ?string $unmatchedReason = null,
    ) {}

    public static function parsed(ParsedReceiptDto $receipt): self
    {
        return new self(kind: MatchOutcomeKind::Parsed, parsed: $receipt, skipReason: null);
    }

    public static function skipped(string $reason): self
    {
        return new self(kind: MatchOutcomeKind::Skipped, parsed: null, skipReason: $reason);
    }

    public static function unmatched(?string $reason = null): self
    {
        return new self(kind: MatchOutcomeKind::Unmatched, parsed: null, skipReason: null, unmatchedReason: $reason);
    }
}
