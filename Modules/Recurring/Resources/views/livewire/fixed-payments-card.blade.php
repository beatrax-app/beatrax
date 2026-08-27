@use('Modules\Core\Public\Navigation\Destination')
@use('Modules\Core\Public\Support\Lang')
{{--
    Inline dashboard card — top six approved recurring series by
    monthly equivalent, with a filter toggle (`All series` /
    `This month only`) persisted via #[Url]. Each row links into the
    drill-in via `route('recurring.series.show', ...)`. The footer
    carries the "View all →" anchor to `/recurring`.

    Blade default `{{ }}` escaping for every interpolation.
--}}

@php
    use Modules\Ledger\Public\ValueObjects\Money;

    $fmt = static fn (Money $money): string => $money->format();
@endphp

<x-core::card tag="section" aria-label="{{ Lang::get('recurring::fixed_payments.heading') }}">
    <header class="mb-4 flex flex-wrap items-baseline justify-between gap-4">
        <div>
            <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('recurring::fixed_payments.heading') }}</h2>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400" style="font-variant-numeric: tabular-nums;">
                {{ $fmt($totals->expense) }} {{ Lang::get('recurring::fixed_payments.summary.expenses') }} · {{ $fmt($totals->income) }} {{ Lang::get('recurring::fixed_payments.summary.income') }} · <span class="font-medium text-slate-900 dark:text-slate-100">{{ $fmt($totals->net) }} {{ Lang::get('recurring::fixed_payments.summary.net') }}</span>@if ($totals->isPartial())<span class="text-slate-600 dark:text-slate-400" data-not-converted="true"> {{ Lang::get('core::money.not_converted', ['list' => $totals->unconvertedList()]) }}</span>@endif
            </p>
        </div>
        <div
            class="inline-flex flex-wrap items-center rounded-md border border-slate-200 bg-slate-50 p-0.5 text-xs dark:bg-slate-900 dark:border-slate-700"
            role="group"
            aria-label="{{ Lang::get('recurring::fixed_payments.filter_aria') }}"
        >
            <button
                type="button"
                wire:click="setFilter('all')"
                @class([
                    'rounded-md px-2 py-1',
                    'bg-white font-medium text-slate-900 shadow-sm dark:bg-slate-950 dark:text-slate-100' => $filter === 'all',
                    'text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-100' => $filter !== 'all',
                ])
            >{{ Lang::get('recurring::fixed_payments.filter_all') }}</button>
            <button
                type="button"
                wire:click="setFilter('this-month')"
                @class([
                    'rounded-md px-2 py-1',
                    'bg-white font-medium text-slate-900 shadow-sm dark:bg-slate-950 dark:text-slate-100' => $filter === 'this-month',
                    'text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-100' => $filter !== 'this-month',
                ])
            >{{ Lang::get('recurring::fixed_payments.filter_this_month') }}</button>
        </div>
    </header>

    @if (count($rows) === 0)
        @if ($filter === 'this-month')
            <p class="text-sm text-slate-500 dark:text-slate-400">{{ Lang::get('recurring::fixed_payments.empty_this_month') }}</p>
        @else
            <p class="text-sm text-slate-500 dark:text-slate-400">{{ Lang::get('recurring::fixed_payments.empty_all') }}</p>
        @endif
    @else
        {{-- ============================================================
             PHONE card-list (visible only at <768px)
             Each card links to the series detail page.
             ============================================================ --}}
        <div class="md:hidden overflow-hidden rounded-lg border border-slate-200 dark:border-slate-700 -mx-6 mb-4">
            @foreach ($rows as $row)
                <a
                    href="{{ route('recurring.series.show', ['seriesId' => $row->seriesId]) }}"
                    class="card-list-item block"
                    data-testid="fixed-payment-card-{{ $row->seriesId }}"
                >
                    <div class="min-w-0 flex-1">
                        <p class="primary truncate">{{ $row->displayName() }}</p>
                        <p class="secondary mt-0.5 truncate">
                            <x-core::status-pill class="uppercase tracking-wide">{{ $row->direction }}</x-core::status-pill>
                            <span class="ml-1">{{ $row->cadence->label() }}</span>
                            @if ($row->latestFundingChainLinkId !== null)
                                · {{ Lang::get('recurring::fixed_payments.chain') }}
                            @endif
                        </p>
                    </div>
                    <span class="amount" style="font-variant-numeric: tabular-nums;">{{ $fmt($row->monthlyEquivalent) }}{{ Lang::get('recurring::fixed_payments.per_month_suffix') }}</span>
                </a>
            @endforeach
        </div>

        {{-- ============================================================
             DESKTOP row-list (visible only at >=768px)
             Markup byte-identical to original.
             ============================================================ --}}
        <ul class="hidden md:block divide-y divide-slate-100 dark:divide-slate-800">
            @foreach ($rows as $row)
                <li class="flex items-center justify-between gap-4 py-2">
                    <div class="min-w-0 flex-1">
                        <a
                            href="{{ route('recurring.series.show', ['seriesId' => $row->seriesId]) }}"
                            class="block truncate text-sm font-medium text-slate-900 hover:underline underline-offset-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:text-slate-100"
                        >{{ $row->displayName() }}</a>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            <x-core::status-pill class="uppercase tracking-wide">{{ $row->direction }}</x-core::status-pill>
                            <span class="ml-2">{{ $row->cadence->label() }}</span>
                            @if ($row->latestFundingChainLinkId !== null)
                                <span
                                    role="img"
                                    class="ml-2 inline-flex items-center rounded-full bg-indigo-50 px-1.5 py-0.5 text-[10px] font-medium text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300"
                                    data-chain-badge="true"
                                    aria-label="{{ Lang::get('recurring::fixed_payments.chain_aria') }}"
                                >{{ Lang::get('recurring::fixed_payments.chain') }}</span>
                            @endif
                        </p>
                    </div>
                    <span class="shrink-0 text-sm text-slate-700 dark:text-slate-300" style="font-variant-numeric: tabular-nums;">{{ $fmt($row->monthlyEquivalent) }}{{ Lang::get('recurring::fixed_payments.per_month_suffix') }}</span>
                </li>
            @endforeach
        </ul>
    @endif

    <footer class="mt-4 text-right">
        <a
            href="{{ Destination::Recurring->url() }}"
            class="tap-link text-xs text-slate-500 underline underline-offset-2 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:text-slate-400 dark:hover:text-slate-100"
        >{{ Lang::get('recurring::fixed_payments.view_all') }}</a>
    </footer>
</x-core::card>
