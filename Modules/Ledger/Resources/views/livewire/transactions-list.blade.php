@php
    use Modules\Ledger\Public\ValueObjects\Money;

    // EUR amounts render in Dutch locale (e.g. `€ 68,86`); non-EUR amounts
    // render in US English locale (e.g. `$74.43`) so the symbol prefix
    // matches the user's mental model of the foreign currency. brick/money
    // routes the locale through ext-intl's NumberFormatter under the hood.
    $fmt = static fn (Money $money): string => $money->currency() === 'EUR'
        ? $money->format('nl_NL')
        : $money->format('en_US');
@endphp

<div class="space-y-6">
    <header class="flex items-end justify-between gap-4">
        <div class="space-y-1">
            <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">Transactions</h1>
            <p class="text-sm text-slate-500 dark:text-slate-400">
                {{ $fullHistory ? 'Full history.' : 'Recent transactions (last 90 days).' }}
            </p>
        </div>
        <div class="flex items-center gap-2">
            <flux:radio.group wire:model.live="currency" variant="segmented" aria-label="Currency view">
                <flux:radio value="eur" label="EUR only" />
                <flux:radio value="original" label="Original currency" />
            </flux:radio.group>
            <button
                type="button"
                wire:click="toggleFullHistory"
                class="inline-flex items-center rounded-md border border-slate-200 px-3 py-2 text-sm text-slate-900 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:hover:bg-slate-900 dark:text-slate-100 dark:border-slate-700"
            >
                {{ $fullHistory ? 'Show recent only' : 'Show full history' }}
            </button>
        </div>
    </header>

    @if (count($page->rows) === 0)
        <p class="rounded-lg border border-slate-200 bg-white px-6 py-12 text-center text-sm text-slate-500 dark:bg-slate-950 dark:text-slate-400 dark:border-slate-700">
            Nothing here for this period.
        </p>
    @else
        <div class="overflow-hidden rounded-lg border border-slate-200 dark:border-slate-700">
            <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-700">
                <thead class="bg-slate-50 dark:bg-slate-900">
                    <tr>
                        <th scope="col" class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Date</th>
                        <th scope="col" class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Counterparty</th>
                        <th scope="col" class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Category</th>
                        <th scope="col" class="px-4 py-2 text-right text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Amount</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 bg-white dark:bg-slate-950 dark:divide-slate-700">
                    @foreach ($page->rows as $row)
                        <tr>
                            <td class="px-4 py-2 text-slate-900 dark:text-slate-100" style="font-variant-numeric: tabular-nums;">{{ $row->bookedAt }}</td>
                            {{-- Counterparty is the drill-in affordance —
                                 wire:navigate keeps the SPA-style transition
                                 the rest of the app uses; underline-on-hover
                                 keeps the row chrome quiet at rest. From the
                                 detail page the "View chain" button surfaces
                                 the cross-account chain drawer. The chain-
                                 link badge appears next to rows that are
                                 already part of a confirmed/candidate
                                 chain_link, so the user knows which rows
                                 lead somewhere when they click.  --}}
                            <td class="px-4 py-2 text-slate-900 dark:text-slate-100">
                                <a
                                    href="{{ route('transactions.show', ['transactionId' => $row->id]) }}"
                                    wire:navigate
                                    class="underline-offset-2 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:focus-visible:ring-slate-100"
                                    data-testid="tx-row-link-{{ $row->id }}"
                                >{{ $row->counterpartyName ?? '—' }}</a>
                                @if (isset(($chainTxIds ?? [])[$row->id]))
                                    <span
                                        class="ml-1.5 inline-flex items-center gap-0.5 text-[10px] font-medium uppercase tracking-wide text-emerald-700 dark:text-emerald-400"
                                        title="Part of a chain — open this row to view"
                                        data-testid="tx-row-chain-badge-{{ $row->id }}"
                                    >
                                        <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 015.656 5.656l-3 3a4 4 0 01-5.656-5.656M10.172 13.828a4 4 0 01-5.656-5.656l3-3a4 4 0 015.656 5.656"/>
                                        </svg>
                                        chain
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-slate-500 dark:text-slate-400">
                                @livewire(
                                    'categorization.inline-category-picker',
                                    ['transactionId' => $row->id, 'categoryId' => $row->categoryId],
                                    key('cat-picker-' . $row->id)
                                )
                            </td>
                            <td class="px-4 py-2 text-right" style="font-variant-numeric: tabular-nums;">
                                <span class="block text-sm text-slate-900 dark:text-slate-100">{{ $fmt($row->amount) }}</span>
                                @if ($currency === 'original' && $row->secondaryAmount !== null)
                                    <span class="mt-1 block text-xs text-slate-500 dark:text-slate-400">{{ $fmt($row->secondaryAmount) }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($page->hasMore && $page->nextCursorId !== null)
            <div class="flex justify-center">
                <button
                    type="button"
                    wire:click="loadMore({{ $page->nextCursorId }}, @js($page->nextCursorPostedAt))"
                    class="inline-flex items-center rounded-md border border-slate-200 px-4 py-2 text-sm text-slate-900 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:hover:bg-slate-900 dark:text-slate-100 dark:border-slate-700"
                >Load more</button>
            </div>
        @endif
    @endif
</div>
