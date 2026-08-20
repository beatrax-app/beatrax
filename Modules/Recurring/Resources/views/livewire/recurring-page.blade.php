@use('Modules\Core\Public\Support\Lang')
{{--
    /recurring page — grouped expense + income + transfers sections
    with the net-flow header at the top of the page.

    Each row carries the latest amount (original currency primary,
    EUR shadow when the original currency is not EUR), the EUR
    monthly equivalent (the detector applies the per-cadence
    multiplier at write time so this read site only formats), a
    chain badge when a confirmed/candidate funding chain is attached,
    a category badge (read-only via MerchantMemoryQuery), and the
    next-expected-charge text — rendered dim/italic via
    `data-confidence-low="true"` when the cadence standard deviation
    tripped the low-confidence signal.

    Blade default `{{ }}` escaping for every interpolation. No raw
    HTML output anywhere.
--}}

@php
    use Modules\Ledger\Public\ValueObjects\Money;

    $fmt = static fn (Money $money): string => $money->currency() === 'EUR'
        ? $money->format('nl_NL')
        : $money->format('en_US');

    $eurFmt = static fn (int $minor): string => Money::ofMinor($minor, 'EUR')->format('nl_NL');

    $expenseTotal = (int) ($totals['expense_eur_minor'] ?? 0);
    $incomeTotal = (int) ($totals['income_eur_minor'] ?? 0);
    $netTotal = (int) ($totals['net_eur_minor'] ?? 0);

    $expenses = $sections['expenses'] ?? [];
    $income = $sections['income'] ?? [];
    $transfers = $sections['transfers'] ?? [];

    $sectionEmpty = count($expenses) === 0 && count($income) === 0;
@endphp

<div class="mx-auto max-w-5xl px-4 py-12">
    <header class="mb-8">
        <div class="flex items-baseline justify-between gap-4">
            <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">{{ Lang::get('recurring::index.title') }}</h1>
            <button
                type="button"
                wire:click="reDetect"
                class="inline-flex items-center gap-1 rounded-md border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-900"
            >{{ Lang::get('recurring::index.re_detect') }}</button>
        </div>
        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
            {{ Lang::get('recurring::index.subtitle') }}
        </p>

        @unless ($sectionEmpty)
            <div class="mt-6 flex flex-wrap items-baseline gap-4 rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700 dark:bg-slate-900 dark:border-slate-700 dark:text-slate-300">
                <span style="font-variant-numeric: tabular-nums;">{{ $eurFmt($expenseTotal) }}</span>
                <span class="text-slate-400 dark:text-slate-500" aria-hidden="true">{{ Lang::get('recurring::index.net_flow.expenses') }}</span>
                <span class="text-slate-300 dark:text-slate-600" aria-hidden="true">+</span>
                <span style="font-variant-numeric: tabular-nums;">{{ $eurFmt($incomeTotal) }}</span>
                <span class="text-slate-400 dark:text-slate-500" aria-hidden="true">{{ Lang::get('recurring::index.net_flow.income') }}</span>
                <span class="text-slate-300 dark:text-slate-600" aria-hidden="true">=</span>
                <span class="font-medium text-slate-900 dark:text-slate-100" style="font-variant-numeric: tabular-nums;">{{ $eurFmt($netTotal) }}</span>
                <span class="text-slate-400 dark:text-slate-500" aria-hidden="true">{{ Lang::get('recurring::index.net_flow.net_per_month') }}</span>
            </div>
        @endunless
    </header>

    @if ($sectionEmpty)
        <x-core::card>
            <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('recurring::index.empty.heading') }}</h2>
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                {{ Lang::get('recurring::index.empty.before_link') }}
                <a href="{{ route('recurring.review') }}" class="text-slate-900 underline underline-offset-2 dark:text-slate-100">/recurring/review</a>
                {{ Lang::get('recurring::index.empty.after_link') }}
            </p>
        </x-core::card>
    @else
        @if (count($expenses) > 0)
            <section class="mb-8">
                <h2 class="mb-3 text-sm font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('recurring::index.expenses_heading') }}</h2>

                {{-- ============================================================
                     PHONE card-list for expenses (visible only at <768px)
                     ============================================================ --}}
                <div class="md:hidden overflow-hidden rounded-lg border border-slate-200 dark:border-slate-700">
                    @foreach ($expenses as $row)
                        <a
                            href="{{ route('recurring.series.show', ['seriesId' => $row->seriesId]) }}"
                            class="card-list-item block"
                            data-testid="recurring-expense-card-{{ $row->seriesId }}"
                        >
                            <div class="min-w-0 flex-1">
                                <p class="primary line-clamp-2">{{ $row->displayName() }}</p>
                                <p class="secondary mt-0.5 truncate">
                                    {{ $row->cadence->label() }}
                                    @if ($row->nextExpectedAt)
                                        · {{ $row->nextExpectedAt->translatedFormat('d M Y') }}
                                    @endif
                                    @if ($row->latestFundingChainLinkId !== null)
                                        · {{ Lang::get('recurring::index.chain') }}
                                    @endif
                                </p>
                            </div>
                            <span class="amount" style="font-variant-numeric: tabular-nums;">{{ $eurFmt($row->monthlyEquivalent->toMinor()) }}{{ Lang::get('recurring::index.per_month_suffix') }}</span>
                        </a>
                    @endforeach
                </div>

                {{-- ============================================================
                     DESKTOP card-list (visible only at >=768px)
                     Markup byte-identical to original.
                     ============================================================ --}}
                <ul class="hidden md:block space-y-2">
                    @foreach ($expenses as $row)
                        <x-core::card tag="li" padding="tight">
                            <div class="flex items-center justify-between gap-4">
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm text-slate-900 dark:text-slate-100">
                                        <a
                                            href="{{ route('recurring.series.show', ['seriesId' => $row->seriesId]) }}"
                                            class="font-medium text-slate-900 hover:underline underline-offset-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:text-slate-100"
                                        >{{ $row->displayName() }}</a>
                                        <span class="ml-2 text-slate-500 dark:text-slate-400" style="font-variant-numeric: tabular-nums;">{{ $fmt($row->latestAmount) }}</span>
                                        @if ($row->latestAmount->currency() !== 'EUR' && $row->eurEquivalent !== null)
                                            <span class="ml-1 text-xs text-slate-400 dark:text-slate-500" style="font-variant-numeric: tabular-nums;" data-eur-shadow="true">{{ $fmt($row->eurEquivalent) }}</span>
                                        @endif
                                    </p>
                                    <p
                                        class="mt-1 text-xs {{ $row->nextExpectedConfidenceLow ? 'italic text-slate-400 dark:text-slate-500' : 'text-slate-500 dark:text-slate-400' }}"
                                        data-confidence-low="{{ $row->nextExpectedConfidenceLow ? 'true' : 'false' }}"
                                    >
                                        {{ $row->cadence->label() }}
                                        @if ($row->nextExpectedAt)
                                            · {{ Lang::get('recurring::index.next') }} {{ $row->nextExpectedAt->translatedFormat('d M Y') }}
                                        @endif
                                    </p>
                                </div>
                                <div class="flex shrink-0 items-center gap-3">
                                    @if ($row->latestFundingChainLinkId !== null)
                                        <span
                                            class="inline-flex items-center gap-1 rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300"
                                            data-chain-badge="true"
                                            aria-label="{{ Lang::get('recurring::index.chain_aria') }}"
                                        >{{ Lang::get('recurring::index.chain') }}</span>
                                    @endif
                                    <span class="text-sm text-slate-700 dark:text-slate-300" style="font-variant-numeric: tabular-nums;">{{ $eurFmt($row->monthlyEquivalent->toMinor()) }}{{ Lang::get('recurring::index.per_month_suffix') }}</span>
                                </div>
                            </div>
                        </x-core::card>
                    @endforeach
                </ul>
            </section>
        @endif

        @if (count($income) > 0)
            <section class="mb-8">
                <h2 class="mb-3 text-sm font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('recurring::index.income_heading') }}</h2>

                {{-- ============================================================
                     PHONE card-list for income (visible only at <768px)
                     ============================================================ --}}
                <div class="md:hidden overflow-hidden rounded-lg border border-slate-200 dark:border-slate-700">
                    @foreach ($income as $row)
                        <a
                            href="{{ route('recurring.series.show', ['seriesId' => $row->seriesId]) }}"
                            class="card-list-item block"
                            data-testid="recurring-income-card-{{ $row->seriesId }}"
                        >
                            <div class="min-w-0 flex-1">
                                <p class="primary line-clamp-2">{{ $row->displayName() }}</p>
                                <p class="secondary mt-0.5 truncate">
                                    {{ $row->cadence->label() }}
                                    @if ($row->nextExpectedAt)
                                        · {{ $row->nextExpectedAt->translatedFormat('d M Y') }}
                                    @endif
                                </p>
                            </div>
                            <span class="amount positive" style="font-variant-numeric: tabular-nums;">{{ $eurFmt($row->monthlyEquivalent->toMinor()) }}{{ Lang::get('recurring::index.per_month_suffix') }}</span>
                        </a>
                    @endforeach
                </div>

                {{-- ============================================================
                     DESKTOP card-list for income (visible only at >=768px)
                     Markup byte-identical to original.
                     ============================================================ --}}
                <ul class="hidden md:block space-y-2">
                    @foreach ($income as $row)
                        <x-core::card tag="li" padding="tight">
                            <div class="flex items-center justify-between gap-4">
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm text-slate-900 dark:text-slate-100">
                                        <a
                                            href="{{ route('recurring.series.show', ['seriesId' => $row->seriesId]) }}"
                                            class="font-medium text-slate-900 hover:underline underline-offset-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:text-slate-100"
                                        >{{ $row->displayName() }}</a>
                                        <span class="ml-2 text-slate-500 dark:text-slate-400" style="font-variant-numeric: tabular-nums;">{{ $fmt($row->latestAmount) }}</span>
                                        @if ($row->latestAmount->currency() !== 'EUR' && $row->eurEquivalent !== null)
                                            <span class="ml-1 text-xs text-slate-400 dark:text-slate-500" style="font-variant-numeric: tabular-nums;" data-eur-shadow="true">{{ $fmt($row->eurEquivalent) }}</span>
                                        @endif
                                    </p>
                                    <p
                                        class="mt-1 text-xs {{ $row->nextExpectedConfidenceLow ? 'italic text-slate-400 dark:text-slate-500' : 'text-slate-500 dark:text-slate-400' }}"
                                        data-confidence-low="{{ $row->nextExpectedConfidenceLow ? 'true' : 'false' }}"
                                    >
                                        {{ $row->cadence->label() }}
                                        @if ($row->nextExpectedAt)
                                            · {{ Lang::get('recurring::index.next') }} {{ $row->nextExpectedAt->translatedFormat('d M Y') }}
                                        @endif
                                    </p>
                                </div>
                                <div class="flex shrink-0 items-center gap-3">
                                    @if ($row->latestFundingChainLinkId !== null)
                                        <span
                                            class="inline-flex items-center gap-1 rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300"
                                            data-chain-badge="true"
                                            aria-label="{{ Lang::get('recurring::index.chain_aria') }}"
                                        >{{ Lang::get('recurring::index.chain') }}</span>
                                    @endif
                                    <span class="text-sm text-slate-700 dark:text-slate-300" style="font-variant-numeric: tabular-nums;">{{ $eurFmt($row->monthlyEquivalent->toMinor()) }}{{ Lang::get('recurring::index.per_month_suffix') }}</span>
                                </div>
                            </div>
                        </x-core::card>
                    @endforeach
                </ul>
            </section>
        @endif
    @endif

    <section class="mt-8">
        <details
            class="rounded-lg border border-slate-200 bg-white dark:bg-slate-950 dark:border-slate-700"
            @if ($transfersExpanded) open data-transfers-open="true" @endif
        >
            <summary
                class="cursor-pointer px-4 py-3 text-sm font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400"
                wire:click.prevent="toggleTransfers"
            >{{ Lang::get('recurring::index.transfers.heading') }}</summary>
            <div class="border-t border-slate-200 px-4 py-3 text-xs text-slate-500 dark:border-slate-700 dark:text-slate-400">
                @if (count($transfers) === 0)
                    {{ Lang::get('recurring::index.transfers.empty') }}
                @else
                    <ul class="space-y-1">
                        @foreach ($transfers as $row)
                            <li>{{ $row->displayName() }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </details>
    </section>
</div>
