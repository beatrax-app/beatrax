<?php

declare(strict_types=1);

namespace Modules\Counterparties\Public\Queries;

// Hero-section shape for a profile page: identity plus hero stats
// (12-month total for spend types, net received for personal,
// transaction count). iban IS populated for personal rows; every
// rendering path gates on the user's Show-IBAN opt-in before echoing it.
final readonly class CounterpartyProfileDto
{
    public function __construct(
        public int $id,
        public string $slug,
        public string $displayName,
        public string $type,
        public ?string $iban,
        public ?string $merchantName,
        public int $total12mMinor,
        public int $transactionCount,
        public ?string $firstSeenDate,
        public ?string $lastSeenDate,
    ) {}
}
