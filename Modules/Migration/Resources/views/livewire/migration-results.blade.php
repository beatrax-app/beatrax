@use('Modules\Core\Public\Support\Lang')
@php
    $stats = [
        ['label' => Lang::get('migration::results.stats.category'), 'value' => $run->categories_count],
        ['label' => Lang::get('migration::results.stats.account'), 'value' => $run->accounts_count],
        ['label' => Lang::get('migration::results.stats.payee'), 'value' => $run->counterparties_resolved_count],
        ['label' => Lang::get('migration::results.stats.transaction'), 'value' => $run->transactions_inserted_count],
        ['label' => Lang::get('migration::results.stats.budget'), 'value' => $budgetMonthsCount],
    ];

    $summaryLine = Lang::get('migration::results.summary_line', [
        'categories' => $run->categories_count,
        'budget_months' => $budgetMonthsCount,
        'transactions' => $run->transactions_inserted_count,
    ]);
    if ($stillNeedsAttention > 0) {
        $summaryLine .= ' '.Lang::get('migration::results.summary_attention', ['count' => $stillNeedsAttention]);
    }
@endphp

<div class="space-y-6">
    <header class="space-y-1">
        <h1 class="text-2xl font-semibold text-slate-900 tracking-tight dark:text-slate-100">
            {{ $isReconciliation ? Lang::get('migration::results.heading_update') : Lang::get('migration::results.heading_complete') }}
        </h1>
    </header>

    <aside
        aria-live="polite" aria-atomic="true"
        class="rounded-md border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-700 dark:bg-emerald-950 dark:border-emerald-800 dark:text-emerald-200"
        data-testid="migration-success-banner"
    >
        {{ $summaryLine }}
    </aside>

    <section class="grid grid-cols-2 gap-4 sm:grid-cols-5" style="font-feature-settings: 'tnum';">
        @foreach ($stats as $stat)
            <div class="rounded-md border border-slate-200 bg-slate-50 p-4 dark:bg-slate-900 dark:border-slate-700">
                <p class="text-2xl font-semibold text-slate-900 dark:text-slate-100">{{ $stat['value'] }}</p>
                <p class="mt-1 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ $stat['label'] }}</p>
            </div>
        @endforeach
    </section>

    @if ($unmapped !== null && $stillNeedsAttention > 0)
        <section class="space-y-3">
            @foreach (['category' => Lang::get('migration::results.groups.category'), 'payee' => Lang::get('migration::results.groups.payee'), 'extra' => Lang::get('migration::results.groups.extra'), 'conflict' => Lang::get('migration::results.groups.conflict')] as $groupKey => $groupLabel)
                @if ($unmapped[$groupKey]['count'] > 0)
                    <details class="rounded-md border border-slate-200 dark:border-slate-700">
                        <summary class="cursor-pointer px-4 py-2 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                            {{ $groupLabel }} ({{ $unmapped[$groupKey]['count'] }})
                        </summary>
                        <ul class="space-y-1 border-t border-slate-200 px-4 py-3 text-sm dark:border-slate-700">
                            @foreach ($unmapped[$groupKey]['items'] as $item)
                                <li class="text-slate-900 dark:text-slate-100">{{ $item['label'] }} <span class="text-slate-500 dark:text-slate-400">— {{ $item['reason'] }}</span></li>
                            @endforeach
                        </ul>
                    </details>
                @endif
            @endforeach
        </section>
    @endif

    <div class="flex flex-wrap gap-4 text-sm">
        @if ($run->transactions_inserted_count > 0)
            <a href="{{ route('transactions.index') }}" class="font-medium text-slate-900 underline underline-offset-2 hover:text-slate-700 dark:hover:text-slate-300 dark:text-slate-100">{{ Lang::get('migration::results.view_transactions') }}</a>
        @endif
        @if ($budgetMonthsCount > 0)
            <a href="{{ route('budgets.index') }}" class="font-medium text-slate-900 underline underline-offset-2 hover:text-slate-700 dark:hover:text-slate-300 dark:text-slate-100">{{ Lang::get('migration::results.view_budgets') }}</a>
        @endif
    </div>

    <div>
        <a
            href="{{ route('migrations.index') }}"
            class="inline-flex items-center rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-900 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:hover:bg-slate-900 dark:bg-slate-950 dark:text-slate-100 dark:border-slate-700"
        >
            {{ Lang::get('migration::results.back') }}
        </a>
    </div>
</div>
