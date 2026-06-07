@php
    use Modules\Ledger\Public\ValueObjects\Money;

    $fmt = static fn (int $minor): string => Money::ofMinor($minor, $netWorth->currency)->format('nl_NL');
    $amountClass = static fn (int $minor): string => $minor < 0
        ? 'text-rose-600 dark:text-rose-400'
        : 'text-slate-900 dark:text-slate-100';
@endphp

<div>
    @if ($netWorth->hasAccounts())
        <section class="rounded-lg border border-slate-200 bg-white p-6 dark:border-slate-700 dark:bg-slate-950" aria-label="Net worth">
            <div class="flex items-start justify-between gap-4">
                <div class="min-w-0">
                    <p class="text-xs uppercase tracking-wide text-slate-400 dark:text-slate-500">Net worth</p>
                    <p class="mt-1 text-3xl font-semibold {{ $amountClass($netWorth->totalMinor) }}" style="font-variant-numeric: tabular-nums;">{{ $fmt($netWorth->totalMinor) }}</p>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                        across {{ count($netWorth->accounts) }} {{ count($netWorth->accounts) === 1 ? 'account' : 'accounts' }}
                        @if ($netWorth->hasExcludedAccounts)
                            <span class="text-amber-700 dark:text-amber-400">· excludes non-EUR balances</span>
                        @endif
                    </p>
                </div>
                <button
                    type="button"
                    wire:click="toggle"
                    class="shrink-0 rounded-md border border-slate-200 bg-white px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300 dark:hover:bg-slate-800"
                >{{ $expanded ? 'Hide' : 'Breakdown' }}</button>
            </div>

            @if ($expanded)
                <ul class="mt-4 space-y-1.5 border-t border-slate-100 pt-4 dark:border-slate-800">
                    @foreach ($netWorth->accounts as $account)
                        <li class="flex items-center justify-between gap-3 text-sm">
                            <span class="min-w-0 flex-1 truncate text-slate-700 dark:text-slate-300">
                                {{ $account->name }}
                                @if ($account->isLiability)
                                    <span class="ml-1 text-xs text-slate-400 dark:text-slate-500">(card)</span>
                                @endif
                            </span>
                            <span class="shrink-0 font-medium {{ $amountClass($account->balanceMinor) }}" style="font-variant-numeric: tabular-nums;">
                                {{ $fmt($account->balanceMinor) }}@if ($account->currency !== $netWorth->currency)<span class="ml-1 text-xs text-slate-400">{{ $account->currency }}</span>@endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endif
</div>
