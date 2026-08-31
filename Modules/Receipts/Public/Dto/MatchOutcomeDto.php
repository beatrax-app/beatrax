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
        public readonly ?string $matcherKey = null,
    ) {}

    // Stamped by the registry from the matcher that answered, which is the only
    // place that fact is known first-hand; a matcher writing its own key into
    // the untyped raw payload made every reader guess it back out of a mixed.
    public function fromMatcher(string $matcherKey): self
    {
        return new self(
            kind: $this->kind,
            parsed: $this->parsed,
            skipReason: $this->skipReason,
            unmatchedReason: $this->unmatchedReason,
            matcherKey: $matcherKey,
        );
    }

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
