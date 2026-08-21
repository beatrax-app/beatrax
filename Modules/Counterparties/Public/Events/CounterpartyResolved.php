<?php

declare(strict_types=1);

namespace Modules\Counterparties\Public\Events;

// Dispatched on every counterparty upsert except the self_account branch,
// which never writes a row. No listeners yet.
final readonly class CounterpartyResolved
{
    public function __construct(
        public int $counterpartyId,
        public int $userId,
        public string $type,
    ) {}
}
