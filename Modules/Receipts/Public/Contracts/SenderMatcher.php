<?php

declare(strict_types=1);

namespace Modules\Receipts\Public\Contracts;

use Modules\EmailScan\Public\Dto\InboxMessageDto;
use Modules\Receipts\Public\Dto\MatchOutcomeDto;

// Implemented by every per-sender matcher; MatcherRegistry collects
// them via the receipts.matcher container tag and dispatches in
// priority order until one returns true from canHandle(). Matchers are
// pure functions of their input — no DB reads, no per-call state.
interface SenderMatcher
{
    // Stable, lowercase-kebab identifier persisted on
    // inbox_messages.matcher_key for audit + re-parse traceability.
    public function key(): string;

    // Higher value = earlier in dispatch order. Default sender-specific
    // matchers use 100; a future generic fallback would use 0.
    public function priority(): int;

    // The authoritative filter — dispatch stops at the first matcher
    // returning true and never consults the others, regardless of
    // their priority.
    public function canHandle(InboxMessageDto $msg): bool;

    // May return MatchOutcomeDto::skipped(...) when canHandle() claimed
    // responsibility but the body is not a transaction.
    public function match(string $emlRaw): MatchOutcomeDto;
}
