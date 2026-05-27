<?php

declare(strict_types=1);

namespace Modules\Counterparties\Public\Events;

/**
 * Dispatched whenever `CounterpartyResolver::resolve()` upserts a
 * `counterparties` row (every non-`self_account` branch — types
 * `merchant`, `personal`, `bank`, `government`, `unknown`).
 *
 * Public coupling point for cross-module consumers that want to react
 * to counterparty creation / re-resolution without importing the
 * Counterparties module's internals. v1.0.0 ships zero listeners; the
 * event exists so future surfaces (counterparty-merge dev action,
 * triage-queue notifications, audit log) can subscribe without a
 * resolver-service rewrite.
 *
 * The `self_account` branch does NOT fire this event because it does
 * not write a counterparty row.
 */
final readonly class CounterpartyResolved
{
    public function __construct(
        public int $counterpartyId,
        public int $userId,
        public string $type,
    ) {}
}
