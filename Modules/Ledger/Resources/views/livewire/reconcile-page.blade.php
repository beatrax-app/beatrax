@php
    use Modules\Ledger\Public\ValueObjects\Money;

    /**
     * @var \Illuminate\Support\Collection<int,object{id:int,name:string,kind:string}> $accounts
     * @var bool $hasAccount
     * @var int $clearedBalanceMinor
     * @var int|null $statementTargetMinor
     * @var int|null $differenceMinor
     * @var bool $isMatched
     */
    $fmt = static fn (int $minor): string => Money::ofMinor($minor, 'EUR')->format('nl_NL');
    $input = 'block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:focus-visible:ring-slate-100';

    $hasTarget = $statementTargetMinor !== null;
    $pillVariant = ! $hasAccount || ! $hasTarget ? 'muted' : ($isMatched ? 'ok' : 'fail');
@endphp

<div class="mx-auto max-w-3xl px-4 py-12">
    <header class="mb-8">
        <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">Reconcile</h1>
        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
            Confirm an account's statement balance against your cleared transactions. When they match,
            complete the reconcile to lock those rows in place.
        </p>
    </header>

    <div class="rounded-xl border border-slate-200 bg-white p-6 space-y-6 dark:border-slate-800 dark:bg-slate-950">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div class="space-y-1">
                <label for="rc-account" class="block text-sm text-slate-900 dark:text-slate-100">Account</label>
                <select id="rc-account" wire:model.live="accountId" class="{{ $input }}">
                    <option value="">Choose an account…</option>
                    @foreach ($accounts as $account)
                        <option value="{{ $account->id }}">{{ $account->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="space-y-1">
                <label for="rc-date" class="block text-sm text-slate-900 dark:text-slate-100">Statement date</label>
                <input id="rc-date" type="date" wire:model.live="statementDate" class="{{ $input }}" />
            </div>
        </div>

        <div class="space-y-1">
            <label for="rc-balance" class="block text-sm text-slate-900 dark:text-slate-100">Statement balance (€)</label>
            <input
                id="rc-balance"
                type="text"
                inputmode="decimal"
                wire:model.live="statementBalance"
                placeholder="0,00"
                class="{{ $input }}"
            />
            <p class="text-xs text-slate-400 dark:text-slate-500">
                Pre-filled from your latest imported statement when available — negative for money owed, editable either way.
            </p>
        </div>

        @if ($error !== '')
            <p class="text-sm text-rose-600 dark:text-rose-500">{{ $error }}</p>
        @endif

        <div class="rounded-lg border border-slate-100 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-900">
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <dl>
                    <dt class="text-xs uppercase tracking-wide text-slate-400 dark:text-slate-500">Cleared balance</dt>
                    <dd class="mt-1 font-medium text-slate-900 dark:text-slate-100" style="font-variant-numeric: tabular-nums;">
                        {{ $fmt($clearedBalanceMinor) }}
                    </dd>
                </dl>
                <dl>
                    <dt class="text-xs uppercase tracking-wide text-slate-400 dark:text-slate-500">Statement target</dt>
                    <dd class="mt-1 font-medium text-slate-900 dark:text-slate-100" style="font-variant-numeric: tabular-nums;">
                        {{ $hasTarget ? $fmt($statementTargetMinor) : '—' }}
                    </dd>
                </dl>
                <dl>
                    <dt class="text-xs uppercase tracking-wide text-slate-400 dark:text-slate-500">Difference</dt>
                    <dd class="mt-1" style="font-variant-numeric: tabular-nums;">
                        @if (! $hasAccount)
                            <span class="status-pill muted"><span class="dot"></span> choose an account</span>
                        @elseif (! $hasTarget)
                            <span class="status-pill muted"><span class="dot"></span> enter a statement balance</span>
                        @elseif ($isMatched)
                            <span class="status-pill ok"><span class="dot"></span> matched — {{ $fmt(0) }}</span>
                        @else
                            <span class="status-pill fail" data-testid="reconcile-discrepancy">
                                <span class="dot"></span> discrepancy — {{ $fmt($differenceMinor) }}
                            </span>
                        @endif
                    </dd>
                </dl>
            </div>

            @if ($hasAccount && $hasTarget && ! $isMatched)
                <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">
                    The statement balance doesn't match your cleared balance yet. Toggle cleared rows on the
                    <a href="{{ route('transactions.index') }}" class="underline">transactions list</a> or adjust the
                    entered balance until the difference reaches zero — this flow never creates a balancing entry.
                </p>
            @endif
        </div>

        <div class="flex items-center justify-between">
            <button type="button" wire:click="checkDiscrepancy" class="pill-btn-ghost">
                Check
            </button>
            <button
                type="button"
                wire:click="confirmReconcile"
                @disabled(! $isMatched)
                class="pill-btn-primary"
            >
                Complete reconcile
            </button>
        </div>
    </div>
</div>
