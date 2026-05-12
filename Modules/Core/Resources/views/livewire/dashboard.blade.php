@php
    use Modules\Ledger\Public\ValueObjects\Money;

    /**
     * Currency formatting delegates to the Money value object's localised
     * format() so multi-currency rows render with the correct symbol and
     * decimal precision (JPY 0 digits, KWD 3 digits, EUR 2 digits, etc.).
     * No float arithmetic happens on the minor amount — brick/money formats
     * directly from the integer representation.
     */
    $fmt = static fn (Money $money): string => $money->format('nl_NL');
@endphp

<div
    class="space-y-12"
    x-data
    x-on:keydown.window.left="if (!['INPUT','TEXTAREA','SELECT'].includes(document.activeElement?.tagName)) $wire.previousPeriod()"
    x-on:keydown.window.right="if (!['INPUT','TEXTAREA','SELECT'].includes(document.activeElement?.tagName)) $wire.nextPeriod()"
    x-on:keydown.window.t="if (!['INPUT','TEXTAREA','SELECT'].includes(document.activeElement?.tagName)) $wire.today()"
>
    <header class="flex items-start justify-between gap-6">
        <div class="space-y-1">
            <h1 class="text-3xl font-semibold tracking-tight text-slate-900">{{ $summary->period->label }}</h1>
            <p class="text-sm text-slate-500">This period at a glance.</p>
        </div>
        <div class="flex items-center gap-1">
            <button
                type="button"
                wire:click="previousPeriod"
                aria-label="Previous period"
                class="inline-flex h-10 w-10 items-center justify-center rounded-md text-slate-500 hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
            >&lsaquo;</button>
            <button
                type="button"
                wire:click="today"
                class="inline-flex h-10 items-center rounded-md px-3 text-sm text-slate-500 hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
            >Today</button>
            <button
                type="button"
                wire:click="nextPeriod"
                aria-label="Next period"
                class="inline-flex h-10 w-10 items-center justify-center rounded-md text-slate-500 hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
            >&rsaquo;</button>
        </div>
    </header>

    {{-- KPI tiles: the primary focal point of the dashboard. --}}
    <section class="grid grid-cols-1 gap-4 md:grid-cols-3" aria-label="This period totals">
        <div class="rounded-lg border border-slate-200 bg-white p-6">
            <p class="text-xs uppercase tracking-wide text-slate-500">In</p>
            <p class="mt-2 text-3xl font-semibold text-slate-900" style="font-variant-numeric: tabular-nums;">
                {{ $fmt($summary->inflow) }}
            </p>
        </div>
        <div class="rounded-lg border border-slate-200 bg-white p-6">
            <p class="text-xs uppercase tracking-wide text-slate-500">Out</p>
            <p class="mt-2 text-3xl font-semibold text-slate-900" style="font-variant-numeric: tabular-nums;">
                {{ $fmt($summary->outflow) }}
            </p>
        </div>
        <div class="rounded-lg border border-slate-200 bg-white p-6">
            <p class="text-xs uppercase tracking-wide text-slate-500">Net</p>
            <p
                class="mt-2 text-3xl font-semibold {{ $summary->net->isNegative() ? 'text-slate-900' : 'text-emerald-600' }}"
                style="font-variant-numeric: tabular-nums;"
            >
                {{ $fmt($summary->net) }}
            </p>
        </div>
    </section>

    {{-- Top spending categories --}}
    <section class="space-y-4">
        <h2 class="text-xl font-semibold text-slate-900">Top spending</h2>
        @if (count($summary->topCategories) === 0)
            <p class="text-sm text-slate-500">No categorized expenses yet.</p>
        @else
            <ul class="space-y-3">
                @foreach ($summary->topCategories as $cat)
                    <li class="space-y-1">
                        <div class="flex items-baseline justify-between text-sm">
                            <span class="text-slate-900">{{ $cat->name }}</span>
                            <span class="text-slate-900" style="font-variant-numeric: tabular-nums;">
                                {{ $fmt($cat->spend) }}
                            </span>
                        </div>
                        <div class="h-1 w-full overflow-hidden rounded-full bg-slate-200">
                            @php
                                $rawPct = (int) round($cat->percentageOfTotal * 100);
                                $barWidth = $rawPct === 0 ? 0 : max(2, min(100, $rawPct));
                            @endphp
                            <div
                                class="h-1 bg-slate-900"
                                style="width: {{ $barWidth }}%;"
                            ></div>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- Recent transactions --}}
    <section class="space-y-4">
        <div class="flex items-center justify-between">
            <h2 class="text-xl font-semibold text-slate-900">Recent transactions</h2>
            <a
                href="{{ route('transactions.index') }}"
                class="text-sm text-slate-500 underline underline-offset-2 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2"
            >View all</a>
        </div>

        @if (count($summary->recentTransactions) === 0)
            <p class="text-sm text-slate-500">Nothing here for this period.</p>
        @else
            <div class="overflow-hidden rounded-lg border border-slate-200">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50">
                        <tr>
                            <th scope="col" class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wide text-slate-500">Date</th>
                            <th scope="col" class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wide text-slate-500">Counterparty</th>
                            <th scope="col" class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wide text-slate-500">Category</th>
                            <th scope="col" class="px-4 py-2 text-right text-xs font-medium uppercase tracking-wide text-slate-500">Amount</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 bg-white">
                        @foreach ($summary->recentTransactions as $row)
                            <tr>
                                <td class="px-4 py-2 text-slate-900" style="font-variant-numeric: tabular-nums;">{{ $row->bookedAt }}</td>
                                <td class="px-4 py-2 text-slate-900">{{ $row->counterpartyName ?? '—' }}</td>
                                <td class="px-4 py-2 text-slate-500">{{ $row->categoryName ?? 'Uncategorized' }}</td>
                                <td class="px-4 py-2 text-right text-slate-900" style="font-variant-numeric: tabular-nums;">
                                    {{ $fmt($row->amount) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
