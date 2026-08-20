<?php

declare(strict_types=1);

namespace Modules\Counterparties\Public\Events;

// Dispatched on every counterparty upsert (all types except
// self_account, which never writes a row). Ships with zero listeners;
// reserved for future merge/audit/notification surfaces to subscribe
// without a resolver-service rewrite.
final readonly class CounterpartyResolved
{
    public function __construct(
        public int $counterpartyId,
        public int $userId,
        public string $type,
    ) {}
}
