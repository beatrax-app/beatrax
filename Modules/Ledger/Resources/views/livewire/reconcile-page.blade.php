@use('Modules\Core\Public\Navigation\Destination')
@use('Modules\Core\Public\Support\Fmt')
@use('Modules\Core\Public\Support\Lang')
@php
    use Modules\Ledger\Public\ValueObjects\Money;
    use Modules\Ledger\Public\ValueObjects\MoneyInput;

    /**
     * @var \Illuminate\Support\Collection<int,object{id:int,name:string,kind:string}> $accounts
     * @var bool $hasAccount
     * @var int|null $clearedBalanceMinor
     * @var int|null $statementTargetMinor
     * @var int|null $differenceMinor
     * @var bool $isMatched
     * @var string $statementCurrency
     * @var bool $isReachable
     * @var bool $hasBaseline
     * @var int $lockableCount
     * @var \Carbon\CarbonImmutable|null $reconciledThrough
     */
    $fmt = static fn (int $minor): string => Money::ofMinor($minor, $statementCurrency)->format();

    $hasTarget = $statementTargetMinor !== null;

    // Null is "no window to sum over" — the date picker offers Clear — and the
    // cleared balance, the difference and the advice are all unanswerable then.
    $hasWindow = $clearedBalanceMinor !== null;

    // A matched difference is only half the question Complete answers. The
    // other half is whether the write has anything to transition, and an
    // account already reconciled to this date has nothing.
    $nothingToLock = $isMatched && $lockableCount === 0;
@endphp

<div class="mx-auto max-w-3xl px-4 py-6">
    <header class="mb-8">
        <x-core::page-heading>
            {{ Lang::get('ledger::reconcile.heading') }}
            <x-slot:tip>
                <x-core::help-tip
                    topic="reconcile"
                    :label="Lang::get('ledger::reconcile.heading')"
                    :body="Lang::get('ledger::help.reconcile', ['complete' => Lang::get('ledger::reconcile.complete')])"
                />
            </x-slot:tip>
        </x-core::page-heading>
        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
            {{ Lang::get('ledger::reconcile.intro') }}
        </p>
    </header>

    <div class="rounded-xl border border-slate-200 bg-white p-6 space-y-6 dark:border-slate-800 dark:bg-slate-950">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div class="space-y-1">
                <x-core::form-field
                    name="accountId"
                    field-id="rc-account"
                    type="select"
                    :label="Lang::get('ledger::reconcile.account')"
                    wire:model.number.live="accountId"
                >
                    <option value="">{{ Lang::get('ledger::reconcile.choose_account') }}</option>
                    @foreach ($accounts as $account)
                        <option value="{{ $account->id }}">{{ $account->name }}</option>
                    @endforeach
                </x-core::form-field>

                {{-- Beside the account rather than in the difference cell:
                     this is what the account IS, not what this statement's
                     arithmetic came to. Held only in the rows until now, a
                     reconciled account rendered byte-identical to a fresh one. --}}
                @if ($reconciledThrough !== null)
                    <p data-testid="reconcile-reconciled-through">
                        <span class="status-pill ok">
                            <span class="dot"></span>
                            {{ Lang::get('ledger::reconcile.pill.reconciled_through', ['date' => Fmt::shortDate($reconciledThrough)]) }}
                        </span>
                    </p>
                @endif
            </div>
            <div class="space-y-1">
                <label for="rc-date" class="block text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('ledger::reconcile.statement_date') }}</label>
                <x-core::date-input field-id="rc-date" wire:model.live="statementDate" :aria-label="Lang::get('ledger::reconcile.statement_date')" />
            </div>
        </div>

        <x-core::form-field
            name="statementBalance"
            field-id="rc-balance"
            :label="Lang::get('ledger::reconcile.statement_balance', ['symbol' => Money::symbolFor($statementCurrency)])"
            :hint="Lang::get('ledger::reconcile.balance_help')"
            inputmode="{{ MoneyInput::decimalPlaces($statementCurrency) === 0 ? 'numeric' : 'decimal' }}"
            wire:model.live="statementBalance"
            :placeholder="MoneyInput::formatAbsMinor(0, $statementCurrency)"
        />

        @if ($error !== '')
            <p class="text-sm text-rose-600 dark:text-rose-500">{{ $error }}</p>
        @endif

        <div class="rounded-lg border border-slate-100 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-900">
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <dl>
                    <dt class="text-xs uppercase tracking-wide text-slate-600 dark:text-slate-400">{{ Lang::get('ledger::reconcile.cleared_balance') }}</dt>
                    <dd class="mt-1 font-medium text-slate-900 dark:text-slate-100" style="font-variant-numeric: tabular-nums;">
                        {{ $hasWindow ? $fmt($clearedBalanceMinor) : '—' }}
                    </dd>
                </dl>
                <dl>
                    <dt class="text-xs uppercase tracking-wide text-slate-600 dark:text-slate-400">{{ Lang::get('ledger::reconcile.statement_target') }}</dt>
                    <dd class="mt-1 font-medium text-slate-900 dark:text-slate-100" style="font-variant-numeric: tabular-nums;">
                        {{ $hasTarget ? $fmt($statementTargetMinor) : '—' }}
                    </dd>
                </dl>
                <dl>
                    <dt class="text-xs uppercase tracking-wide text-slate-600 dark:text-slate-400">{{ Lang::get('ledger::reconcile.difference') }}</dt>
                    <dd class="mt-1" style="font-variant-numeric: tabular-nums;">
                        @if (! $hasAccount)
                            <span class="status-pill muted"><span class="dot"></span> {{ Lang::get('ledger::reconcile.pill.choose_account') }}</span>
                        @elseif (! $hasWindow)
                            <span class="status-pill muted"><span class="dot"></span> {{ Lang::get('ledger::reconcile.pill.choose_date') }}</span>
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

            {{-- Advice the reader can act on, or none. Telling someone to
                 toggle rows towards a figure no set of rows can reach sent
                 them round a loop that made the number worse every time. --}}
            @if ($hasAccount && $hasWindow && $hasTarget && ! $isMatched)
                <p class="mt-3 text-xs text-slate-500 dark:text-slate-400" data-testid="reconcile-advice">
                    @if ($isReachable)
                        {!! Lang::get('ledger::reconcile.mismatch_html', ['url' => Destination::Transactions->url()]) !!}
                    @elseif (! $hasBaseline)
                        {!! Lang::get('ledger::reconcile.unreachable_no_baseline_html', ['url' => Destination::Settings->url()]) !!}
                    @else
                        {{ Lang::get('ledger::reconcile.unreachable') }}
                    @endif
                </p>
            @endif
        </div>

        {{-- Above the row, not beside it: a disabled button is out of the tab
             order, so the reason has to be reached before the reader arrives
             at the control it explains. --}}
        @if ($nothingToLock)
            <p id="rc-complete-unavailable" class="text-xs text-slate-500 dark:text-slate-400" data-testid="reconcile-complete-unavailable">
                {{ Lang::get('ledger::reconcile.complete_unavailable') }}
            </p>
        @endif

        <div class="flex items-center justify-between">
            <button type="button" wire:click="checkDiscrepancy" class="pill-btn-ghost">
                {{ Lang::get('ledger::reconcile.check') }}
            </button>
            <button
                type="button"
                wire:click="confirmReconcile"
                @disabled(! $isMatched || $lockableCount === 0)
                @if ($nothingToLock) aria-describedby="rc-complete-unavailable" @endif
                class="pill-btn-primary"
            >
                {{ Lang::get('ledger::reconcile.complete') }}
            </button>
        </div>
    </div>
</div>
