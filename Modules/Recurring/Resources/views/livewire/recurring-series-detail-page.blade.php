@use('Modules\Core\Public\Support\Lang')
{{--
    /recurring/series/{id} drill-in page — full amount-over-time chart
    (native-currency primary + EUR shadow when distinct) over the
    captured occurrences plus the per-occurrence table linking each
    row back to its underlying transaction.

    The ApexCharts chart is initialised by an Alpine `x-init` handler
    that reads the chart options from `data-options` and instantiates
    `window.ApexCharts`. The global is exposed by `resources/js/app.js`
    on first bundle load; the smoke component verifies the load.

    Blade default `{{ }}` escaping for every interpolation.
--}}

@php
    use Modules\Ledger\Public\ValueObjects\Money;

    $fmt = static fn (Money $money): string => $money->currency() === 'EUR'
        ? $money->format('nl_NL')
        : $money->format('en_US');

    $eurFmt = static fn (int $minor): string => Money::ofMinor($minor, 'EUR')->format('nl_NL');

    $chartElementId = 'series-chart-'.$series->seriesId;
    $occurrenceCount = count($occurrences);
@endphp

<div class="mx-auto max-w-5xl px-4 py-12">
    {{-- Mobile top bar (D-05): back affordance targeting /recurring parent list.
         Visible only at <1024px (CSS .top-bar rule sets display:none at >=1024px).
         The page title is the series display name, truncated to one line.
         Must live INSIDE the root div — Livewire allows only one root element. --}}
    <x-core::mobile-top-bar
        :backUrl="route('recurring.index')"
        :title="$series->displayName()"
    />
    <header class="mb-8 flex items-start justify-between gap-4">
        <div class="min-w-0 flex-1">
            <h1 class="truncate text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">{{ $series->displayName() }}</h1>
            <p class="mt-2 flex flex-wrap items-baseline gap-x-3 gap-y-1 text-sm text-slate-500 dark:text-slate-400">
                <span
                    class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700 dark:bg-slate-800 dark:text-slate-300"
                >{{ ucfirst($series->state) }}</span>
                <span
                    class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700 dark:bg-slate-800 dark:text-slate-300"
                >{{ $series->cadence->label() }}</span>
                <span style="font-variant-numeric: tabular-nums;">{{ $fmt($series->latestAmount) }}</span>
                <span class="text-slate-400 dark:text-slate-500" aria-hidden="true">·</span>
                <span style="font-variant-numeric: tabular-nums;">{{ $eurFmt($series->monthlyEquivalent->toMinor()) }}/mo</span>
            </p>
            @if (! empty($counterpartyLink))
                <p class="mt-2 text-sm">
                    <a
                        href="{{ route('counterparties.profile', ['slug' => $counterpartyLink['slug']]) }}"
                        class="text-slate-500 underline underline-offset-2 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-100"
                    >{{ Lang::get('recurring::detail.view_profile', ['name' => $counterpartyLink['displayName']]) }}</a>
                </p>
            @endif
        </div>
        {{-- Desktop controls: shrink-0 row; wraps naturally at phone width --}}
        <div class="flex shrink-0 flex-wrap items-center gap-4">
            @livewire('drift-alerts.drift-threshold-editor', ['recurringSeriesId' => $series->seriesId], key('threshold-detail-'.$series->seriesId))
            @livewire('forecasting.model-what-if-dropdown', ['seriesId' => $series->seriesId], key('what-if-'.$series->seriesId))
            <div x-data="{ open: false }" class="relative">
                <button
                    type="button"
                    x-on:click="open = ! open"
                    aria-haspopup="listbox"
                    aria-label="{{ Lang::get('recurring::detail.variance_tolerance_aria') }}"
                    class="inline-flex items-center gap-1 rounded-md border border-slate-200 bg-white px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-900"
                >
                    <span class="text-slate-500 dark:text-slate-400">{{ Lang::get('recurring::detail.tolerance') }}</span>
                    <span style="font-variant-numeric: tabular-nums;">{{ $series->varianceTolerancePercent }}%</span>
                </button>
                <div
                    x-show="open"
                    x-cloak
                    x-on:click.outside="open = false"
                    role="listbox"
                    aria-label="{{ Lang::get('recurring::detail.variance_tolerance_aria') }}"
                    class="absolute right-0 z-10 mt-1 w-32 rounded-md border border-slate-200 bg-white p-1 text-xs shadow-lg dark:bg-slate-950 dark:border-slate-700"
                >
                    @foreach ([10, 25, 50] as $percent)
                        <button
                            type="button"
                            wire:click="editVarianceTolerance({{ $percent }})"
                            x-on:click="open = false"
                            @class([
                                'block w-full rounded-md px-2 py-1 text-left hover:bg-slate-50 dark:hover:bg-slate-900',
                                'font-medium text-slate-900 dark:text-slate-100' => $series->varianceTolerancePercent === $percent,
                                'text-slate-500 dark:text-slate-400' => $series->varianceTolerancePercent !== $percent,
                            ])
                        >{{ $percent }}%</button>
                    @endforeach
                </div>
            </div>
            {{-- Back link: visible at desktop (mobile top bar handles phone D-05) --}}
            <a
                href="{{ route('recurring.index') }}"
                class="hidden md:inline text-sm text-slate-500 underline underline-offset-2 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:text-slate-400 dark:hover:text-slate-100"
            >{{ Lang::get('recurring::detail.back') }}</a>
        </div>
    </header>

    <section class="mb-8 rounded-lg border border-slate-200 bg-white p-6 dark:bg-slate-950 dark:border-slate-700">
        <div class="mb-4 flex items-baseline justify-between">
            <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('recurring::detail.amount_over_time') }}</h2>
            <button
                type="button"
                wire:click="toggleAllPoints"
                class="text-xs text-slate-500 underline underline-offset-2 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:text-slate-400 dark:hover:text-slate-100"
            >{{ $showAllPoints ? Lang::get('recurring::detail.show_recent') : Lang::get('recurring::detail.view_all_points') }}</button>
        </div>

        @include('recurring::livewire.partials.recurring-detail-chart-options', [
            'apexOptions' => $apexOptions,
            'chartElementId' => $chartElementId,
        ])
    </section>

    <section>
        <h2 class="mb-3 text-sm font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('recurring::detail.occurrences') }}</h2>
        @if ($occurrenceCount === 0)
            <div class="rounded-lg border border-slate-200 bg-white p-6 dark:bg-slate-950 dark:border-slate-700">
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ Lang::get('recurring::detail.no_occurrences') }}</p>
            </div>
        @else
            {{-- overflow-x: auto wrapper ensures the occurrences table is scrollable
                 at phone width without horizontal page overflow (D-06). --}}
            <div style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
            <div class="overflow-hidden rounded-lg border border-slate-200 dark:border-slate-700" style="min-width: 360px;">
                <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-700">
                    <thead class="bg-slate-50 dark:bg-slate-900">
                        <tr>
                            <th scope="col" class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('recurring::detail.table.date') }}</th>
                            <th scope="col" class="px-4 py-2 text-right text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('recurring::detail.table.amount') }}</th>
                            <th scope="col" class="px-4 py-2 text-right text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('recurring::detail.table.transaction') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 bg-white dark:bg-slate-950 dark:divide-slate-700">
                        @foreach ($occurrences as $occ)
                            <tr>
                                <td class="px-4 py-2 text-slate-900 dark:text-slate-100" style="font-variant-numeric: tabular-nums;">{{ $occ->observedAt->format('d M Y') }}</td>
                                <td class="px-4 py-2 text-right text-slate-900 dark:text-slate-100" style="font-variant-numeric: tabular-nums;">{{ $fmt($occ->observedAmount) }}</td>
                                <td class="px-4 py-2 text-right text-sm">
                                    <a
                                        href="{{ route('transactions.show', ['transactionId' => $occ->transactionId]) }}"
                                        class="text-slate-500 underline underline-offset-2 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:text-slate-400 dark:hover:text-slate-100"
                                    >#{{ $occ->transactionId }}</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            </div>{{-- end overflow-x scroller --}}
        @endif
    </section>
</div>
