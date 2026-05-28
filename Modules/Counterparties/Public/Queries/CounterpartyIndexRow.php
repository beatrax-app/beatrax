<?php

declare(strict_types=1);

namespace Modules\Counterparties\Public\Queries;

/**
 * Output shape of a single row on the `/counterparties` index.
 *
 * Carries the columns the index card / list-row needs to render
 * without re-querying — the headline display name, the slug used for
 * profile-page routing, the type taxonomy chip, the 12-month signed
 * money total, the per-month average, and a 12-bar sparkline that the
 * card renders directly as a `.cp-spark` strip.
 *
 * Personal-type rows carry `iban` as null at this DTO layer even when
 * the underlying counterparty row has one — the privacy default lives
 * here too: the index never exposes a personal IBAN under any
 * rendering path.
 */
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
