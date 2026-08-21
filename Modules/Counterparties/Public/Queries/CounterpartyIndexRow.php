<?php

declare(strict_types=1);

namespace Modules\Counterparties\Public\Queries;

// There is deliberately no `iban` field: the index must not be able to
// expose a personal IBAN under any rendering path.
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
