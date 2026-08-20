@use('Modules\Core\Public\Support\Lang')
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
    $fmt = static fn (int $minor): string => Money::ofMinor($minor, 'EUR')->format();

    $hasTarget = $statementTargetMinor !== null;
    $pillVariant = ! $hasAccount || ! $hasTarget ? 'muted' : ($isMatched ? 'ok' : 'fail');
@endphp

<div class="mx-auto max-w-3xl px-4 py-12">
    <header class="mb-8">
        <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">{{ Lang::get('ledger::reconcile.heading') }}</h1>
        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
            {{ Lang::get('ledger::reconcile.intro') }}
        </p>
    </header>

    <div class="rounded-xl border border-slate-200 bg-white p-6 space-y-6 dark:border-slate-800 dark:bg-slate-950">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <x-core::form-field
                name="accountId"
                field-id="rc-account"
                type="select"
                :label="Lang::get('ledger::reconcile.account')"
                wire:model.live="accountId"
            >
                <option value="">{{ Lang::get('ledger::reconcile.choose_account') }}</option>
                @foreach ($accounts as $account)
                    <option value="{{ $account->id }}">{{ $account->name }}</option>
                @endforeach
            </x-core::form-field>
            <div class="space-y-1">
                <label for="rc-date" class="block text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('ledger::reconcile.statement_date') }}</label>
                <x-core::date-input field-id="rc-date" wire:model.live="statementDate" />
            </div>
        </div>

        <x-core::form-field
            name="statementBalance"
            field-id="rc-balance"
            :label="Lang::get('ledger::reconcile.statement_balance')"
            :hint="Lang::get('ledger::reconcile.balance_help')"
            inputmode="decimal"
            wire:model.live="statementBalance"
            placeholder="0,00"
        />

        @if ($error !== '')
            <p class="text-sm text-rose-600 dark:text-rose-500">{{ $error }}</p>
        @endif

        <div class="rounded-lg border border-slate-100 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-900">
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <dl>
                    <dt class="text-xs uppercase tracking-wide text-slate-400 dark:text-slate-500">{{ Lang::get('ledger::reconcile.cleared_balance') }}</dt>
                    <dd class="mt-1 font-medium text-slate-900 dark:text-slate-100" style="font-variant-numeric: tabular-nums;">
                        {{ $fmt($clearedBalanceMinor) }}
                    </dd>
                </dl>
                <dl>
                    <dt class="text-xs uppercase tracking-wide text-slate-400 dark:text-slate-500">{{ Lang::get('ledger::reconcile.statement_target') }}</dt>
                    <dd class="mt-1 font-medium text-slate-900 dark:text-slate-100" style="font-variant-numeric: tabular-nums;">
                        {{ $hasTarget ? $fmt($statementTargetMinor) : '—' }}
                    </dd>
                </dl>
                <dl>
                    <dt class="text-xs uppercase tracking-wide text-slate-400 dark:text-slate-500">{{ Lang::get('ledger::reconcile.difference') }}</dt>
                    <dd class="mt-1" style="font-variant-numeric: tabular-nums;">
                        @if (! $hasAccount)
                            <span class="status-pill muted"><span class="dot"></span> {{ Lang::get('ledger::reconcile.pill.choose_account') }}</span>
                        @elseif (! $hasTarget)
                            <span class="status-pill muted"><span class="dot"></span> {{ Lang::get('ledger::reconcile.pill.enter_balance') }}</span>
                        @elseif ($isMatched)
                            <span class="status-pill ok"><span class="dot"></span> {{ Lang::get('ledger::reconcile.pill.matched', ['amount' => $fmt(0)]) }}</span>
                        @else
                            <span class="status-pill fail" data-testid="reconcile-discrepancy">
                                <span class="dot"></span> {{ Lang::get('ledger::reconcile.pill.discrepancy', ['amount' => $fmt($differenceMinor)]) }}
                            </span>
                        @endif
                    </dd>
                </dl>
            </div>

            @if ($hasAccount && $hasTarget && ! $isMatched)
                <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">
                    {!! Lang::get('ledger::reconcile.mismatch_html', ['url' => route('transactions.index')]) !!}
                </p>
            @endif
        </div>

        <div class="flex items-center justify-between">
            <button type="button" wire:click="checkDiscrepancy" class="pill-btn-ghost">
                {{ Lang::get('ledger::reconcile.check') }}
            </button>
            <button
                type="button"
                wire:click="confirmReconcile"
                @disabled(! $isMatched)
                class="pill-btn-primary"
            >
                {{ Lang::get('ledger::reconcile.complete') }}
            </button>
        </div>
    </div>
</div>
