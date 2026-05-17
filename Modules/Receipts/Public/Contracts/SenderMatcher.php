<?php

declare(strict_types=1);

namespace Modules\Receipts\Public\Contracts;

use Modules\EmailScan\Public\Dto\InboxMessageDto;
use Modules\Receipts\Public\Dto\MatchOutcomeDto;

/**
 * Strategy interface implemented by every per-sender receipt matcher.
 *
 * The MatcherRegistry collects implementations via the
 * `receipts.matcher` container tag and dispatches them in priority
 * order (highest first) until one returns true from `canHandle()`.
 *
 * Matchers are pure functions of their input: they read nothing from
 * the database, hold no per-call state, and may be bound as singletons.
 * Side effects (status transitions, transaction writes) live downstream
 * in RecordReceipt and the SourceAdapter pipeline.
 */
interface SenderMatcher
{
    /**
     * Stable, lowercase-kebab matcher identifier, e.g. 'paypal-receipt'.
     * This value is persisted on `inbox_messages.matcher_key` so audit
     * + re-parse paths can resolve back to the matcher that owned a
     * given row.
     */
    public function key(): string;

    /**
     * Higher value = earlier in dispatch order. Default sender-specific
     * matchers use 100; future generic fallbacks would use 0.
     */
    public function priority(): int;

    /**
     * True when this matcher claims responsibility for the given
     * message. `canHandle()` is the authoritative filter — the dispatch
     * loop stops at the first matcher returning true and never
     * consults the others, even if their priority is lower.
     */
    public function canHandle(InboxMessageDto $msg): bool;

    /**
     * Parse a raw RFC 822 message. May return
     * `MatchOutcomeDto::skipped(...)` when `canHandle()` returned true
     * but the body is not a transaction (e.g. a PayPal login
     * notification rather than a receipt). Returns
     * `MatchOutcomeDto::parsed(...)` on success.
     */
    public function match(string $emlRaw): MatchOutcomeDto;
}
