<?php

declare(strict_types=1);

namespace Modules\Counterparties\Public\Queries;

use Illuminate\Container\Container;
use Illuminate\Contracts\Routing\UrlGenerator;
use Modules\Counterparties\Public\Enums\CounterpartyType;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Ledger\Public\ValueObjects\Money;

// There is deliberately no `iban` field: the index must not be able to
// expose a personal IBAN under any rendering path.
final readonly class CounterpartyIndexRow
{
    // Derived here rather than per loop: the index renders the same row up to
    // three times — cards, the phone list and the desktop table, the last two
    // both emitted and then hidden by CSS — and each copy was formatting the
    // amounts and rebuilding the link again from the same three fields.
    public string $href;

    public string $total12mFormatted;

    public string $avgPerMonthFormatted;

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
    ) {
        $currency = BaseCurrency::value();
        $this->total12mFormatted = Money::ofMinor(abs($total12mMinor), $currency)->format();
        $this->avgPerMonthFormatted = Money::ofMinor(abs($avgPerMonthMinor), $currency)->format();

        $urls = Container::getInstance()->make(UrlGenerator::class);
        $this->href = match ($type) {
            CounterpartyType::SelfAccount->value => '/accounts/'.$slug,
            CounterpartyType::Unknown->value => $urls->route('counterparties.triage', ['queue_first' => $id]),
            default => $urls->route('counterparties.profile', ['slug' => $slug]),
        };
    }
}
