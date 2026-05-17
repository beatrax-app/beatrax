{{--
    /recurring page — grouped expense + income + transfers sections
    with the net-flow header at the top per D-818 / D-819 / D-852.

    Each row carries the latest amount (original currency primary +
    EUR shadow when distinct per D-840), the EUR monthly equivalent
    (with the per-cadence multiplier already applied by the detector
    at write time per D-826), a chain badge when a confirmed/candidate
    funding chain is attached, a category badge (read-only — wired
    behind MerchantMemoryQuery in a later plan), and the next-expected-
    charge text (dim/italic via `data-confidence-low="true"` when the
    cadence stddev tripped the low-confidence signal per D-830).

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
        <h1 class="text-2xl font-semibold tracking-tight text-slate-900">Recurring</h1>
        <p class="mt-2 text-sm text-slate-500">
            Approved subscriptions, fixed payments, and recurring income at a glance.
        </p>

        @unless ($sectionEmpty)
            <div class="mt-6 flex flex-wrap items-baseline gap-4 rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700">
                <span style="font-variant-numeric: tabular-nums;">{{ $eurFmt($expenseTotal) }}</span>
                <span class="text-slate-400" aria-hidden="true">expenses</span>
                <span class="text-slate-300" aria-hidden="true">+</span>
                <span style="font-variant-numeric: tabular-nums;">{{ $eurFmt($incomeTotal) }}</span>
                <span class="text-slate-400" aria-hidden="true">income</span>
                <span class="text-slate-300" aria-hidden="true">=</span>
                <span class="font-medium text-slate-900" style="font-variant-numeric: tabular-nums;">{{ $eurFmt($netTotal) }}</span>
                <span class="text-slate-400" aria-hidden="true">net/month</span>
            </div>
        @endunless
    </header>

    @if ($sectionEmpty)
        <div class="rounded-lg border border-slate-200 bg-white p-6">
            <h2 class="text-base font-semibold text-slate-900">No recurring activity yet</h2>
            <p class="mt-2 text-sm text-slate-500">
                Approve detected suggestions on
                <a href="{{ route('recurring.review') }}" class="text-slate-900 underline underline-offset-2">/recurring/review</a>
                to see them here.
            </p>
        </div>
    @else
        @if (count($expenses) > 0)
            <section class="mb-8">
                <h2 class="mb-3 text-sm font-medium uppercase tracking-wide text-slate-500">Recurring expenses</h2>
                <ul class="space-y-2">
                    @foreach ($expenses as $row)
                        <li class="rounded-lg border border-slate-200 bg-white p-4">
                            <div class="flex items-center justify-between gap-4">
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm text-slate-900">
                                        <a
                                            href="{{ route('recurring.series.show', ['seriesId' => $row->seriesId]) }}"
                                            class="font-medium text-slate-900 hover:underline underline-offset-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
                                        >{{ $row->displayName() }}</a>
                                        <span class="ml-2 text-slate-500" style="font-variant-numeric: tabular-nums;">{{ $fmt($row->latestAmount) }}</span>
                                        @if ($row->latestAmount->currency() !== 'EUR' && $row->eurEquivalent !== null)
                                            <span class="ml-1 text-xs text-slate-400" style="font-variant-numeric: tabular-nums;" data-eur-shadow="true">{{ $fmt($row->eurEquivalent) }}</span>
                                        @endif
                                    </p>
                                    <p
                                        class="mt-1 text-xs {{ $row->nextExpectedConfidenceLow ? 'italic text-slate-400' : 'text-slate-500' }}"
                                        data-confidence-low="{{ $row->nextExpectedConfidenceLow ? 'true' : 'false' }}"
                                    >
                                        {{ ucfirst($row->cadence) }}
                                        @if ($row->nextExpectedAt)
                                            · Next {{ $row->nextExpectedAt->format('d M Y') }}
                                        @endif
                                    </p>
                                </div>
                                <div class="flex shrink-0 items-center gap-3">
                                    @if ($row->latestFundingChainLinkId !== null)
                                        <span
                                            class="inline-flex items-center gap-1 rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700"
                                            data-chain-badge="true"
                                            aria-label="Funded via chain"
                                        >chain</span>
                                    @endif
                                    <span class="text-sm text-slate-700" style="font-variant-numeric: tabular-nums;">{{ $eurFmt($row->monthlyEquivalent->toMinor()) }}/mo</span>
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if (count($income) > 0)
            <section class="mb-8">
                <h2 class="mb-3 text-sm font-medium uppercase tracking-wide text-slate-500">Recurring income</h2>
                <ul class="space-y-2">
                    @foreach ($income as $row)
                        <li class="rounded-lg border border-slate-200 bg-white p-4">
                            <div class="flex items-center justify-between gap-4">
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm text-slate-900">
                                        <a
                                            href="{{ route('recurring.series.show', ['seriesId' => $row->seriesId]) }}"
                                            class="font-medium text-slate-900 hover:underline underline-offset-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
                                        >{{ $row->displayName() }}</a>
                                        <span class="ml-2 text-slate-500" style="font-variant-numeric: tabular-nums;">{{ $fmt($row->latestAmount) }}</span>
                                        @if ($row->latestAmount->currency() !== 'EUR' && $row->eurEquivalent !== null)
                                            <span class="ml-1 text-xs text-slate-400" style="font-variant-numeric: tabular-nums;" data-eur-shadow="true">{{ $fmt($row->eurEquivalent) }}</span>
                                        @endif
                                    </p>
                                    <p
                                        class="mt-1 text-xs {{ $row->nextExpectedConfidenceLow ? 'italic text-slate-400' : 'text-slate-500' }}"
                                        data-confidence-low="{{ $row->nextExpectedConfidenceLow ? 'true' : 'false' }}"
                                    >
                                        {{ ucfirst($row->cadence) }}
                                        @if ($row->nextExpectedAt)
                                            · Next {{ $row->nextExpectedAt->format('d M Y') }}
                                        @endif
                                    </p>
                                </div>
                                <div class="flex shrink-0 items-center gap-3">
                                    @if ($row->latestFundingChainLinkId !== null)
                                        <span
                                            class="inline-flex items-center gap-1 rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700"
                                            data-chain-badge="true"
                                            aria-label="Funded via chain"
                                        >chain</span>
                                    @endif
                                    <span class="text-sm text-slate-700" style="font-variant-numeric: tabular-nums;">{{ $eurFmt($row->monthlyEquivalent->toMinor()) }}/mo</span>
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    @endif

    <section class="mt-8">
        <details
            class="rounded-lg border border-slate-200 bg-white"
            @if ($transfersExpanded) open data-transfers-open="true" @endif
        >
            <summary
                class="cursor-pointer px-4 py-3 text-sm font-medium uppercase tracking-wide text-slate-500"
                wire:click.prevent="toggleTransfers"
            >Recurring transfers</summary>
            <div class="border-t border-slate-200 px-4 py-3 text-xs text-slate-500">
                @if (count($transfers) === 0)
                    No recurring transfers detected.
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
