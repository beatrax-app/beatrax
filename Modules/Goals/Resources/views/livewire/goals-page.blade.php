@use('Modules\Core\Public\Support\Lang')
{{--
    /goals page — list savings goals with 3-state progress bars and projected-
    date copy; Flux create/edit modal with inline field validation; Edit /
    kebab lifecycle (Mark complete, Archive); in-card archive micro-confirm +
    Restore toast; "Archived goals (N)" disclosure.

    Calm-slate direction: emerald in-progress/reached, amber overdue, rose
    archive-action. Tabular mono numerics throughout. h-1.5 (6px) progress
    bar (intentionally slimmer than the 8px Budgets bar). No width transitions
    (prefers-reduced-motion). Blade {{ }} escaping throughout.
--}}

@php
    use Modules\Ledger\Public\ValueObjects\Money;

    $fmt = static fn (int $minor, string $currency): string => Money::ofMinor($minor, $currency)
        ->format($currency === 'EUR' ? 'nl_NL' : 'en_US');

    $progressColor = [
        'in_progress' => 'bg-emerald-500 dark:bg-emerald-400',
        'reached'     => 'bg-emerald-500 dark:bg-emerald-400',
        'overdue'     => 'bg-amber-500 dark:bg-amber-400',
    ];
@endphp

{{--
    Phone responsive pass (D-06, D-10, UI-SPEC §8/§9).

    At <768px:
    - Goals list renders as .card-list-item rows (name .primary, progress/target .secondary)
    - The create/edit flux:modal form is replaced by a bottom sheet that slides up from below
      (x-core::bottom-sheet). Trigger buttons dispatch open-sheet at phone width.
    At >=768px (desktop): card grid + Flux modal unchanged.
--}}
<div class="mx-auto max-w-3xl px-4 py-12">
    {{-- Page header --}}
    <header class="mb-8 flex items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">{{ Lang::get('goals::messages.page.title') }}</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ Lang::get('goals::messages.page.subtitle') }}</p>
        </div>
        {{-- Trigger: phone → dispatch open-sheet; desktop → $flux.modal().show() --}}
        <button
            type="button"
            x-on:click="
                $wire.set('editGoalId', 0);
                if (window.innerWidth < 768) {
                    $dispatch('open-sheet', { name: 'goal-form' });
                } else {
                    $flux.modal('goal-form').show();
                }
            "
            class="inline-flex shrink-0 items-center rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-white"
        >{{ Lang::get('goals::messages.page.add_goal') }}</button>
    </header>

    {{-- Active + completed goals list --}}
    @if (count($rows) === 0)
        <div class="rounded-lg border border-slate-200 bg-white p-6 dark:bg-slate-950 dark:border-slate-700">
            <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('goals::messages.empty.heading') }}</h2>
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                {{ Lang::get('goals::messages.empty.body') }}
            </p>
            <button
                type="button"
                x-on:click="
                    $wire.set('editGoalId', 0);
                    if (window.innerWidth < 768) {
                        $dispatch('open-sheet', { name: 'goal-form' });
                    } else {
                        $flux.modal('goal-form').show();
                    }
                "
                class="mt-4 inline-flex items-center rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-white"
            >{{ Lang::get('goals::messages.empty.add_first') }}</button>
        </div>
    @else
        {{-- Phone card list (hidden at >=768px via CSS .goals-phone-list display:none) --}}
        <style>
            @media (min-width: 768px) {
                .goals-phone-list { display: none !important; }
            }
            @media (max-width: 767px) {
                .goals-desktop-list { display: none !important; }
            }
        </style>

        {{-- Phone: .card-list-item rows (D-06) --}}
        <ul class="goals-phone-list space-y-0 rounded-lg border border-slate-200 bg-white dark:bg-slate-950 dark:border-slate-700 overflow-hidden">
            @foreach ($rows as $row)
                @php
                    $pct = $row->targetMinor > 0
                        ? (int) round(min(100, $row->fractionComplete * 100))
                        : 0;
                @endphp
                <li class="card-list-item">
                    <div class="flex-1 min-w-0">
                        <p class="primary truncate">{{ $row->name }}</p>
                        <p class="secondary">
                            {{ $fmt($row->contributedMinor, $row->currency) }} / {{ $fmt($row->targetMinor, $row->currency) }}
                            @if ($row->progressState === 'overdue')
                                · <span class="text-amber-600 dark:text-amber-400">{{ Lang::get('goals::messages.status.overdue') }}</span>
                            @elseif ($row->progressState === 'reached')
                                · <span class="text-emerald-600 dark:text-emerald-400">{{ Lang::get('goals::messages.status.reached') }}</span>
                            @endif
                        </p>
                    </div>
                    <span class="amount">{{ $pct }}%</span>
                    {{-- Row actions — always visible on phone (D-12) --}}
                    <button
                        type="button"
                        x-on:click="
                            $wire.openEdit({{ $row->id }});
                            if (window.innerWidth < 768) {
                                $dispatch('open-sheet', { name: 'goal-form' });
                            } else {
                                $flux.modal('goal-form').show();
                            }
                        "
                        class="text-xs text-slate-400 hover:text-slate-900 focus:outline-none dark:hover:text-slate-100 min-w-[44px] min-h-[44px] flex items-center justify-center"
                    >{{ Lang::get('goals::messages.row.edit') }}</button>
                </li>
            @endforeach
        </ul>

        {{-- Desktop: existing card list (unchanged at >=768px) --}}
        <ul class="goals-desktop-list space-y-4">
            @foreach ($rows as $row)
                @php
                    $pct = $row->targetMinor > 0
                        ? (int) round(min(100, $row->fractionComplete * 100))
                        : 0;
                    $barWidth = $pct === 0 ? 0 : max(2, $pct);
                    $isReached = $row->progressState === 'reached';
                    $isOverdue = $row->progressState === 'overdue';
                    $isCompleted = $row->status === \Modules\Goals\Public\Enums\GoalStatus::Completed->value;
                @endphp
                <li
                    class="rounded-lg border border-slate-200 bg-white p-4 dark:bg-slate-950 dark:border-slate-700 {{ $isReached || $isCompleted ? 'border-l-[3px] border-l-emerald-500' : '' }}"
                >
                    {{-- Goal name row + status badge --}}
                    <div class="flex items-center justify-between gap-3">
                        <p class="min-w-0 truncate text-sm font-semibold text-slate-900 dark:text-slate-100">{{ $row->name }}</p>
                        <div class="flex shrink-0 items-center gap-2">
                            @if ($isReached)
                                <span class="inline-flex items-center rounded-full bg-emerald-100 px-2.5 py-[3px] text-xs font-medium text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300">{{ Lang::get('goals::messages.status.reached') }}</span>
                            @elseif ($isOverdue)
                                <span class="inline-flex items-center rounded-full bg-amber-100 px-2.5 py-[3px] text-xs font-medium text-amber-800 dark:bg-amber-900/40 dark:text-amber-300">{{ Lang::get('goals::messages.status.overdue') }}</span>
                            @elseif ($isCompleted)
                                <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-[3px] text-xs font-medium text-slate-600 dark:bg-slate-800 dark:text-slate-400">{{ Lang::get('goals::messages.status.completed') }}</span>
                            @endif
                        </div>
                    </div>

                    {{-- Account link chip (omit row entirely when unlinked) --}}
                    @if ($row->accountName !== null)
                        <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">{{ $row->accountName }}</p>
                    @endif

                    {{-- Progress bar --}}
                    @if (! $isCompleted)
                        <div class="mt-3">
                            <div
                                class="h-1.5 w-full overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700"
                                role="progressbar"
                                aria-valuenow="{{ $pct }}"
                                aria-valuemin="0"
                                aria-valuemax="100"
                                aria-label="{{ Lang::get('goals::messages.progress.aria', ['name' => $row->name, 'pct' => $pct]) }}"
                            >
                                <div
                                    class="h-1.5 rounded-full {{ $progressColor[$row->progressState] }}"
                                    style="width: {{ $barWidth }}%;"
                                ></div>
                            </div>
                        </div>
                    @endif

                    {{-- Contributed / target + projected date --}}
                    <div class="mt-3 flex items-baseline justify-between gap-4">
                        <p class="text-sm" style="font-family: var(--font-mono, ui-monospace, monospace); font-variant-numeric: tabular-nums;">
                            {{ $fmt($row->contributedMinor, $row->currency) }}
                            <span class="text-slate-400 dark:text-slate-500" aria-hidden="true">/</span>
                            {{ $fmt($row->targetMinor, $row->currency) }}
                        </p>
                        <p class="shrink-0 text-xs text-slate-500 dark:text-slate-400">
                            @if ($isCompleted || $isReached)
                                {{ Lang::get('goals::messages.projection.target_reached') }}
                            @elseif ($row->projectedFinishDate === null && $row->contributedMinor <= 0)
                                {{ Lang::get('goals::messages.projection.add_contributions') }}
                            @elseif ($row->projectedFinishDate === null)
                                {{ Lang::get('goals::messages.projection.building') }}
                            @elseif ($row->projectionBeyondHorizon)
                                {{ Lang::get('goals::messages.projection.est', ['date' => \Carbon\CarbonImmutable::parse($row->projectedFinishDate)->isoFormat('D MMM YYYY')]) }}
                                <span class="text-slate-400 dark:text-slate-500">{{ Lang::get('goals::messages.projection.projection_note') }}</span>
                            @else
                                {{ Lang::get('goals::messages.projection.projected', ['date' => \Carbon\CarbonImmutable::parse($row->projectedFinishDate)->isoFormat('D MMM YYYY')]) }}
                            @endif
                        </p>
                    </div>

                    {{-- Archive micro-confirm or footer actions --}}
                    @if ($archivingGoalId === $row->id)
                        <div class="mt-3 flex items-center gap-3 rounded-md bg-slate-50 px-3 py-2 dark:bg-slate-900">
                            <p class="flex-1 text-sm text-slate-700 dark:text-slate-300">{{ Lang::get('goals::messages.archive.confirm_question') }}</p>
                            <button
                                type="button"
                                wire:click="cancelArchive"
                                class="text-sm text-slate-500 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:hover:text-slate-100 dark:text-slate-400"
                            >{{ Lang::get('goals::messages.archive.close') }}</button>
                            <button
                                type="button"
                                wire:click="archive({{ $row->id }})"
                                aria-label="{{ Lang::get('goals::messages.archive.confirm_aria', ['name' => $row->name]) }}"
                                class="text-sm font-medium text-rose-600 hover:text-rose-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600 dark:text-rose-400 dark:hover:text-rose-200"
                            >{{ Lang::get('goals::messages.archive.archive') }}</button>
                        </div>
                    @else
                        <div class="mt-3 flex items-center gap-2">
                            <button
                                type="button"
                                wire:click="openEdit({{ $row->id }})"
                                x-on:click="
                                    if (window.innerWidth < 768) {
                                        $dispatch('open-sheet', { name: 'goal-form' });
                                    } else {
                                        $flux.modal('goal-form').show();
                                    }
                                "
                                class="text-sm text-slate-400 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:hover:text-slate-100"
                            >{{ Lang::get('goals::messages.row.edit') }}</button>

                            <flux:dropdown>
                                <flux:button
                                    variant="ghost"
                                    size="sm"
                                    icon="ellipsis-horizontal"
                                    aria-label="{{ Lang::get('goals::messages.actions.more_aria', ['name' => $row->name]) }}"
                                />
                                <flux:menu>
                                    @if (! $isCompleted)
                                        <flux:menu.item wire:click="markComplete({{ $row->id }})">{{ Lang::get('goals::messages.actions.mark_complete') }}</flux:menu.item>
                                    @endif
                                    <flux:menu.item wire:click="confirmArchive({{ $row->id }})">{{ Lang::get('goals::messages.actions.archive') }}</flux:menu.item>
                                </flux:menu>
                            </flux:dropdown>
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>{{-- end .goals-desktop-list --}}
    @endif

    {{-- Archived goals disclosure --}}
    @if (count($archived) > 0)
        <div class="mt-8">
            <button
                type="button"
                wire:click="$toggle('showArchived')"
                class="flex items-center gap-2 text-sm text-slate-500 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:hover:text-slate-100 dark:text-slate-400"
            >
                <span>{{ Lang::get('goals::messages.archived_disclosure', ['count' => count($archived)]) }}</span>
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-4 w-4 transition-transform {{ $showArchived ? 'rotate-180' : '' }}" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                </svg>
            </button>

            @if ($showArchived)
                <ul class="mt-4 space-y-4">
                    @foreach ($archived as $row)
                        <li class="rounded-lg border border-slate-200 bg-white p-4 opacity-60 dark:bg-slate-950 dark:border-slate-700">
                            <div class="flex items-center justify-between gap-3">
                                <p class="min-w-0 truncate text-sm font-semibold text-slate-500 dark:text-slate-400">{{ $row->name }}</p>
                                <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-[3px] text-xs font-medium text-slate-500 dark:bg-slate-800 dark:text-slate-400">{{ Lang::get('goals::messages.status.archived') }}</span>
                            </div>
                            @if ($row->accountName !== null)
                                <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">{{ $row->accountName }}</p>
                            @endif
                            <div class="mt-3 flex items-baseline justify-between gap-4">
                                <p class="text-sm" style="font-family: var(--font-mono, ui-monospace, monospace); font-variant-numeric: tabular-nums;">
                                    {{ $fmt($row->contributedMinor, $row->currency) }}
                                    <span class="text-slate-400 dark:text-slate-500" aria-hidden="true">/</span>
                                    {{ $fmt($row->targetMinor, $row->currency) }}
                                </p>
                            </div>
                            <div class="mt-3">
                                <flux:dropdown>
                                    <flux:button
                                        variant="ghost"
                                        size="sm"
                                        icon="ellipsis-horizontal"
                                        aria-label="{{ Lang::get('goals::messages.actions.more_aria', ['name' => $row->name]) }}"
                                    />
                                    <flux:menu>
                                        <flux:menu.item wire:click="restore({{ $row->id }})">{{ Lang::get('goals::messages.actions.restore') }}</flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif

    {{-- ------------------------------------------------------------------- --}}
    {{-- Phone bottom sheet (D-10, Pitfall 6)                                --}}
    {{-- At <768px: slides up as a sheet. At >=768px: hidden (flux modal     --}}
    {{-- handles desktop). Same Livewire wire: bindings as the flux modal.   --}}
    {{-- ------------------------------------------------------------------- --}}
    <x-core::bottom-sheet name="goal-form" :title="$editGoalId ? Lang::get('goals::messages.form.title_edit') : Lang::get('goals::messages.form.title_create')">
        <form
            wire:submit="{{ $editGoalId ? 'updateGoal' : 'createGoal' }}"
            class="space-y-4"
        >
            <div>
                <label for="goal-name-sheet" class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('goals::messages.form.name') }}</label>
                <input
                    type="text"
                    id="goal-name-sheet"
                    wire:model="name"
                    placeholder="{{ Lang::get('goals::messages.form.name_placeholder') }}"
                    style="font-size: 16px;"
                    class="block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                />
                @if ($errorName !== '')
                    <p class="mt-1 text-sm text-rose-600 dark:text-rose-400">{{ $errorName }}</p>
                @endif
            </div>

            @php
                $amountCurrencySheet = $baseCurrency;
                if ($editGoalId !== 0) {
                    foreach ($rows as $goalRow) {
                        if ($goalRow->id === $editGoalId) {
                            $amountCurrencySheet = $goalRow->currency;
                            break;
                        }
                    }
                }
            @endphp
            <div>
                <label for="goal-amount-sheet" class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('goals::messages.form.target_amount', ['currency' => $amountCurrencySheet]) }}</label>
                <input
                    type="text"
                    id="goal-amount-sheet"
                    wire:model="targetAmount"
                    inputmode="decimal"
                    placeholder="0.00"
                    style="font-size: 16px; font-variant-numeric: tabular-nums;"
                    class="block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                />
                @if ($errorAmount !== '')
                    <p class="mt-1 text-sm text-rose-600 dark:text-rose-400">{{ $errorAmount }}</p>
                @endif
            </div>

            <div>
                <label for="goal-date-sheet" class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('goals::messages.form.target_date') }}</label>
                <input
                    type="date"
                    id="goal-date-sheet"
                    wire:model="targetDate"
                    style="font-size: 16px;"
                    class="block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                />
                @if ($errorDate !== '')
                    <p class="mt-1 text-sm text-rose-600 dark:text-rose-400">{{ $errorDate }}</p>
                @endif
            </div>

            <div>
                <label for="goal-account-sheet" class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('goals::messages.form.savings_account') }}</label>
                <select
                    id="goal-account-sheet"
                    wire:model.live="accountId"
                    style="font-size: 16px;"
                    class="block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                >
                    <option value="">{{ Lang::get('goals::messages.form.no_account') }}</option>
                    @foreach ($accounts as $account)
                        <option value="{{ $account->id }}">{{ $account->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="flex gap-3 pt-2">
                <button
                    type="submit"
                    class="flex-1 rounded-md bg-slate-900 px-4 py-3 text-sm font-medium text-white hover:bg-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-white"
                >{{ $editGoalId ? Lang::get('goals::messages.form.save_changes') : Lang::get('goals::messages.form.save_goal') }}</button>
                <button
                    type="button"
                    wire:click="cancel"
                    class="rounded-md border border-slate-200 px-4 py-3 text-sm font-medium text-slate-500 hover:text-slate-900 focus:outline-none dark:border-slate-700 dark:hover:text-slate-100"
                >{{ Lang::get('goals::messages.form.close') }}</button>
            </div>
        </form>
    </x-core::bottom-sheet>

    {{-- ------------------------------------------------------------------- --}}
    {{-- Flux create / edit modal (desktop, >=768px)                          --}}
    {{-- ------------------------------------------------------------------- --}}
    <flux:modal name="goal-form" dismissible>
        <div class="pt-[44px]" style="max-width: 520px;">
            <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">
                {{ $editGoalId ? Lang::get('goals::messages.form.title_edit') : Lang::get('goals::messages.form.title_create') }}
            </h2>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                {{ $editGoalId ? Lang::get('goals::messages.form.subtitle_edit') : Lang::get('goals::messages.form.subtitle_create') }}
            </p>

            <form
                wire:submit="{{ $editGoalId ? 'updateGoal' : 'createGoal' }}"
                class="mt-6 space-y-4"
            >
                {{-- Name --}}
                <div>
                    <label for="goal-name" class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('goals::messages.form.name') }}</label>
                    <input
                        type="text"
                        id="goal-name"
                        wire:model="name"
                        placeholder="{{ Lang::get('goals::messages.form.name_placeholder') }}"
                        @if ($errorName !== '') aria-invalid="true" aria-describedby="goal-name-error" @endif
                        class="block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                    />
                    @if ($errorName !== '')
                        <p id="goal-name-error" class="mt-1 text-sm text-rose-600 dark:text-rose-400">{{ $errorName }}</p>
                    @endif
                </div>

                {{-- Target amount --}}
                @php
                    // D-05: target_currency is immutable and can diverge from the
                    // user's current base currency — when editing, label the field
                    // with the goal's own currency so the prefilled amount is not
                    // misread as a base-currency figure (IN-06).
                    $amountCurrency = $baseCurrency;
                    if ($editGoalId !== 0) {
                        foreach ($rows as $goalRow) {
                            if ($goalRow->id === $editGoalId) {
                                $amountCurrency = $goalRow->currency;
                                break;
                            }
                        }
                    }
                @endphp
                <div>
                    <label for="goal-amount" class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('goals::messages.form.target_amount', ['currency' => $amountCurrency]) }}</label>
                    <input
                        type="text"
                        id="goal-amount"
                        wire:model="targetAmount"
                        inputmode="decimal"
                        placeholder="0.00"
                        @if ($errorAmount !== '') aria-invalid="true" aria-describedby="goal-amount-error" @endif
                        class="block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                        style="font-variant-numeric: tabular-nums;"
                    />
                    @if ($errorAmount !== '')
                        <p id="goal-amount-error" class="mt-1 text-sm text-rose-600 dark:text-rose-400">{{ $errorAmount }}</p>
                    @endif
                </div>

                {{-- Target date --}}
                <div>
                    <label for="goal-date" class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('goals::messages.form.target_date') }}</label>
                    <input
                        type="date"
                        id="goal-date"
                        wire:model="targetDate"
                        @if ($errorDate !== '') aria-invalid="true" aria-describedby="goal-date-error" @endif
                        class="block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                    />
                    @if ($errorDate !== '')
                        <p id="goal-date-error" class="mt-1 text-sm text-rose-600 dark:text-rose-400">{{ $errorDate }}</p>
                    @endif
                </div>

                {{-- Savings account (optional) --}}
                <div>
                    <label for="goal-account" class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('goals::messages.form.savings_account') }}</label>
                    <select
                        id="goal-account"
                        wire:model.live="accountId"
                        class="block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                    >
                        <option value="">{{ Lang::get('goals::messages.form.no_account') }}</option>
                        @foreach ($accounts as $account)
                            <option value="{{ $account->id }}">{{ $account->name }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Linked pot (optional) — D-11; settable from goal side --}}
                <div>
                    <label for="goal-pot" class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('goals::messages.form.linked_pot') }}</label>
                    <select
                        id="goal-pot"
                        wire:model="linkedPotId"
                        @if ($accountId === '') disabled @endif
                        class="block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 disabled:opacity-50 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                    >
                        @if ($accountId === '')
                            <option value="" disabled>{{ Lang::get('goals::messages.form.select_account_first') }}</option>
                        @else
                            <option value="">{{ Lang::get('goals::messages.form.no_pot') }}</option>
                            @foreach ($pots as $pot)
                                <option value="{{ $pot->id }}">{{ $pot->name }}</option>
                            @endforeach
                        @endif
                    </select>
                    @if ($errorLinkedPot !== '')
                        <p class="mt-1 text-sm text-rose-600 dark:text-rose-400">{{ $errorLinkedPot }}</p>
                    @endif
                    <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">{{ Lang::get('goals::messages.form.linked_pot_help') }}</p>
                </div>

                {{-- Modal footer --}}
                <div class="flex justify-end gap-2 pt-2">
                    <button
                        type="button"
                        wire:click="cancel"
                        class="rounded-md px-4 py-2 text-sm font-medium text-slate-500 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:hover:text-slate-100 dark:text-slate-400"
                    >{{ Lang::get('goals::messages.form.close') }}</button>
                    <button
                        type="submit"
                        class="inline-flex items-center rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-white"
                    >{{ $editGoalId ? Lang::get('goals::messages.form.save_changes') : Lang::get('goals::messages.form.save_goal') }}</button>
                </div>
            </form>
        </div>
    </flux:modal>
</div>
