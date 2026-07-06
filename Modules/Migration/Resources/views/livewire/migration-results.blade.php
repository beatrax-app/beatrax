@php
    $stats = [
        ['label' => 'Categories', 'value' => $run->categories_count],
        ['label' => 'Accounts', 'value' => $run->accounts_count],
        ['label' => 'Counterparties', 'value' => $run->counterparties_resolved_count],
        ['label' => 'Transactions', 'value' => $run->transactions_inserted_count],
        ['label' => 'Budget months', 'value' => $budgetMonthsCount],
    ];

    $summaryLine = "Imported {$run->categories_count} categories, {$budgetMonthsCount} budget months, and {$run->transactions_inserted_count} transactions.";
    if ($stillNeedsAttention > 0) {
        $summaryLine .= " {$stillNeedsAttention} items still need attention — see below.";
    }
@endphp

<div class="space-y-6">
    <header class="space-y-1">
        <h1 class="text-2xl font-semibold text-slate-900 tracking-tight dark:text-slate-100">
            {{ $isReconciliation ? 'Update applied' : 'Import complete' }}
        </h1>
    </header>

    <aside
        role="status"
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
            @foreach (['category' => 'Still not imported — categories', 'payee' => 'Still not imported — payees', 'extra' => 'Not imported', 'conflict' => 'Needs your decision'] as $groupKey => $groupLabel)
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
            <a href="{{ route('transactions.index') }}" class="font-medium text-slate-900 underline underline-offset-2 hover:text-slate-700 dark:hover:text-slate-300 dark:text-slate-100">View transactions</a>
        @endif
        @if ($budgetMonthsCount > 0)
            <a href="{{ route('budgets.index') }}" class="font-medium text-slate-900 underline underline-offset-2 hover:text-slate-700 dark:hover:text-slate-300 dark:text-slate-100">View budgets</a>
        @endif
    </div>

    <div>
        <a
            href="{{ route('migrations.index') }}"
            class="inline-flex items-center rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-900 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:hover:bg-slate-900 dark:bg-slate-950 dark:text-slate-100 dark:border-slate-700"
        >
            Back to migrations
        </a>
    </div>
</div>
