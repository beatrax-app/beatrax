@php
    $stats = [
        ['key' => 'category', 'label' => 'Categories', 'value' => $summary->categoriesCount],
        ['key' => 'account', 'label' => 'Accounts', 'value' => $summary->accountsCount],
        ['key' => 'payee', 'label' => 'Counterparties', 'value' => $summary->counterpartiesCount],
        ['key' => 'transaction', 'label' => 'Transactions', 'value' => $summary->transactionsCount],
        ['key' => 'budget', 'label' => 'Budget months', 'value' => $summary->budgetMonthsCount],
    ];

    $everythingClean = $summary->unmapped['category']['count'] === 0
        && $summary->unmapped['payee']['count'] === 0
        && $summary->unmapped['extra']['count'] === 0
        && $summary->unmapped['conflict']['count'] === 0;
@endphp

<div class="space-y-8 pb-24">
    <header class="space-y-1">
        <h1 class="text-2xl font-semibold text-slate-900 tracking-tight dark:text-slate-100">Preview import</h1>
        <p class="text-sm text-slate-500 dark:text-slate-400">Review what will change. Nothing is saved until you confirm.</p>
    </header>

    {{-- 5-up mapped-counts stat grid — wraps to 2-up at phone width. --}}
    <section class="grid grid-cols-2 gap-4 sm:grid-cols-5" style="font-feature-settings: 'tnum';">
        @foreach ($stats as $stat)
            <div class="rounded-md border border-slate-200 bg-slate-50 p-4 dark:bg-slate-900 dark:border-slate-700">
                <p class="text-2xl font-semibold text-slate-900 dark:text-slate-100">{{ $stat['value'] }}</p>
                <p class="mt-1 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ $stat['label'] }}</p>
                @if ($this->fullyMapped($summary, $stat['key']))
                    <p class="mt-1 text-xs font-medium text-emerald-700 dark:text-emerald-400">✓ fully mapped</p>
                @endif
            </div>
        @endforeach
    </section>

    {{-- Grouped-section unmapped/conflict list, or the all-clean empty state. --}}
    @if ($everythingClean)
        <p class="text-sm text-slate-500 dark:text-slate-400">Everything mapped cleanly — nothing needs your attention before you confirm.</p>
    @else
        <section class="space-y-3">
            @if ($summary->unmapped['conflict']['count'] > 0)
                <details open class="rounded-md border border-slate-200 dark:border-slate-700" data-testid="conflicts-group">
                    <summary class="cursor-pointer px-4 py-2 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                        Needs your decision ({{ $summary->unmapped['conflict']['count'] }})
                        <span class="ml-1 inline-flex items-center rounded-md bg-amber-50 px-1.5 py-0.5 text-xs font-medium text-amber-700 ring-1 ring-inset ring-amber-600/20 dark:bg-amber-950">{{ $summary->unmapped['conflict']['count'] }}</span>
                    </summary>
                    <div class="space-y-4 border-t border-slate-200 px-4 py-4 dark:border-slate-700">
                        @foreach ($summary->unmapped['conflict']['items'] as $index => $item)
                            @php($resolutionKey = 'c'.$index)
                            <div class="space-y-2 rounded-md bg-slate-50 p-3 dark:bg-slate-900">
                                <p class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ $item['label'] }}</p>
                                <p class="whitespace-pre-line font-mono text-xs text-slate-500 dark:text-slate-400">{{ $item['reason'] }}</p>
                                <div class="inline-flex overflow-hidden rounded-md border border-slate-200 text-xs font-medium dark:border-slate-700" role="group" aria-label="Keep local or take source for {{ $item['label'] }}">
                                    <button
                                        type="button"
                                        wire:click="resolveConflict('{{ $resolutionKey }}', 'keep_local')"
                                        class="px-3 py-1.5 {{ ($conflictResolutions[$resolutionKey] ?? 'keep_local') === 'keep_local' ? 'bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900' : 'bg-white text-slate-700 dark:bg-slate-950 dark:text-slate-300' }}"
                                    >
                                        Keep local
                                    </button>
                                    <button
                                        type="button"
                                        wire:click="resolveConflict('{{ $resolutionKey }}', 'take_source')"
                                        class="px-3 py-1.5 {{ ($conflictResolutions[$resolutionKey] ?? 'keep_local') === 'take_source' ? 'bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900' : 'bg-white text-slate-700 dark:bg-slate-950 dark:text-slate-300' }}"
                                    >
                                        Take source
                                    </button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </details>
            @endif

            @if ($summary->unmapped['category']['count'] > 0)
                <details class="rounded-md border border-slate-200 dark:border-slate-700">
                    <summary class="cursor-pointer px-4 py-2 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                        Unresolved categories ({{ $summary->unmapped['category']['count'] }})
                        <span class="ml-1 inline-flex items-center rounded-md bg-amber-50 px-1.5 py-0.5 text-xs font-medium text-amber-700 ring-1 ring-inset ring-amber-600/20 dark:bg-amber-950">{{ $summary->unmapped['category']['count'] }}</span>
                    </summary>
                    <ul class="space-y-1 border-t border-slate-200 px-4 py-3 text-sm dark:border-slate-700">
                        @foreach ($summary->unmapped['category']['items'] as $item)
                            <li class="text-slate-900 dark:text-slate-100">{{ $item['label'] }} <span class="text-slate-500 dark:text-slate-400">— {{ $item['reason'] }}</span></li>
                        @endforeach
                    </ul>
                </details>
            @endif

            @if ($summary->unmapped['payee']['count'] > 0)
                <details class="rounded-md border border-slate-200 dark:border-slate-700">
                    <summary class="cursor-pointer px-4 py-2 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                        Unresolved payees ({{ $summary->unmapped['payee']['count'] }})
                        <span class="ml-1 inline-flex items-center rounded-md bg-amber-50 px-1.5 py-0.5 text-xs font-medium text-amber-700 ring-1 ring-inset ring-amber-600/20 dark:bg-amber-950">{{ $summary->unmapped['payee']['count'] }}</span>
                    </summary>
                    <ul class="space-y-1 border-t border-slate-200 px-4 py-3 text-sm dark:border-slate-700">
                        @foreach ($summary->unmapped['payee']['items'] as $item)
                            <li class="text-slate-900 dark:text-slate-100">{{ $item['label'] }} <span class="text-slate-500 dark:text-slate-400">— {{ $item['reason'] }}</span></li>
                        @endforeach
                    </ul>
                </details>
            @endif

            @if ($summary->unmapped['extra']['count'] > 0)
                <details class="rounded-md border border-slate-200 dark:border-slate-700">
                    <summary class="cursor-pointer px-4 py-2 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                        Not imported ({{ $summary->unmapped['extra']['count'] }})
                        <span class="ml-1 inline-flex items-center rounded-md bg-amber-50 px-1.5 py-0.5 text-xs font-medium text-amber-700 ring-1 ring-inset ring-amber-600/20 dark:bg-amber-950">{{ $summary->unmapped['extra']['count'] }}</span>
                    </summary>
                    <ul class="space-y-1 border-t border-slate-200 px-4 py-3 text-sm dark:border-slate-700">
                        @foreach ($summary->unmapped['extra']['items'] as $item)
                            <li class="text-slate-900 dark:text-slate-100">{{ $item['label'] }} <span class="text-slate-500 dark:text-slate-400">— {{ $item['reason'] }}</span></li>
                        @endforeach
                    </ul>
                </details>
            @endif
        </section>
    @endif

    {{-- Footer action row — pinned bottom, 1px top border, 24px top padding. --}}
    <footer class="fixed inset-x-0 bottom-0 border-t border-slate-200 bg-white px-6 py-4 dark:bg-slate-950 dark:border-slate-700">
        <div class="mx-auto max-w-5xl space-y-2">
            <p class="text-xs text-slate-500 dark:text-slate-400">This will create or update the counts shown above in your categories, budgets, and ledger.</p>
            <div class="flex items-center justify-between gap-3">
                <button
                    type="button"
                    wire:click="discard"
                    class="rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-900 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:hover:bg-slate-900 dark:bg-slate-950 dark:text-slate-100 dark:border-slate-700"
                >
                    Discard import
                </button>
                <button
                    type="button"
                    wire:click="confirm"
                    class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2 dark:hover:bg-emerald-400 dark:bg-emerald-500"
                >
                    Confirm import
                </button>
            </div>
        </div>
    </footer>
</div>
