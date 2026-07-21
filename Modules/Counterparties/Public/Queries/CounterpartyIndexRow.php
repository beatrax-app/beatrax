<?php

declare(strict_types=1);

namespace Modules\Counterparties\Public\Queries;

// No `iban` field exists on this DTO at all, even for personal-type
// rows that have one on the underlying counterparty row — the index
// never exposes a personal IBAN under any rendering path.
final readonly class CounterpartyIndexRow
{
    /**
     * @param  array<int, int>  $sparkline  12 monthly totals (signed minor units, oldest → newest)
     */
    public function __construct(
        public int $id,
        public string $slug,
        public string $displayName,
        public string $type,
        public int $total12mMinor,
        public int $avgPerMonthMinor,
        public ?string $recentLine,
        public array $sparkline,
    ) {}
}
