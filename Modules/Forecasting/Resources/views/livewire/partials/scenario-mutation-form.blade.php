{{--
    Per-kind scenario mutation inline form. One concrete form layout
    per kind:
      - cancel_series: series dropdown
      - add_one_off: date + amount + currency + direction + note
      - add_recurring: start_date + amount + currency + direction +
        cadence + note
      - change_series_amount: series dropdown + new amount
      - shift_series_date: series dropdown + new next date + scope
        radio (next | all_subsequent)
--}}
<div class="space-y-2">
    @switch($kind)
        @case(\Modules\Forecasting\Public\Enums\ScenarioMutationKind::CancelSeries->value)
            <label class="block text-xs text-slate-500 dark:text-slate-400">Series to cancel
                <select wire:model.live="form.seriesId" class="mt-1 block w-full rounded-md border border-slate-200 px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-slate-900 dark:border-slate-700">
                    <option value="">— pick a series —</option>
                    @foreach ($availableSeries as $opt)
                        <option value="{{ $opt['id'] }}">{{ $opt['name'] }}</option>
                    @endforeach
                </select>
            </label>
            @break

        @case(\Modules\Forecasting\Public\Enums\ScenarioMutationKind::AddOneOff->value)
            <label class="block text-xs text-slate-500 dark:text-slate-400">Date
                <input type="date" wire:model.live="form.date" class="mt-1 block w-full rounded-md border border-slate-200 px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-slate-900 dark:border-slate-700">
            </label>
            <label class="block text-xs text-slate-500 dark:text-slate-400">Amount
                <input type="text" wire:model.live="form.amount" placeholder="50,00" class="mt-1 block w-full rounded-md border border-slate-200 px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-slate-900 dark:border-slate-700">
            </label>
            <label class="block text-xs text-slate-500 dark:text-slate-400">Currency
                <input type="text" wire:model.live="form.currency" placeholder="EUR" maxlength="3" class="mt-1 block w-full rounded-md border border-slate-200 px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-slate-900 dark:border-slate-700">
            </label>
            <label class="block text-xs text-slate-500 dark:text-slate-400">Direction
                <select wire:model.live="form.direction" class="mt-1 block w-full rounded-md border border-slate-200 px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-slate-900 dark:border-slate-700">
                    <option value="expense">Expense (money out)</option>
                    <option value="income">Income (money in)</option>
                </select>
            </label>
            <label class="block text-xs text-slate-500 dark:text-slate-400">Note (optional)
                <input type="text" wire:model.live="form.note" class="mt-1 block w-full rounded-md border border-slate-200 px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-slate-900 dark:border-slate-700">
            </label>
            @break

        @case(\Modules\Forecasting\Public\Enums\ScenarioMutationKind::AddRecurring->value)
            <label class="block text-xs text-slate-500 dark:text-slate-400">Start date
                <input type="date" wire:model.live="form.startDate" class="mt-1 block w-full rounded-md border border-slate-200 px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-slate-900 dark:border-slate-700">
            </label>
            <label class="block text-xs text-slate-500 dark:text-slate-400">Amount
                <input type="text" wire:model.live="form.amount" placeholder="15,00" class="mt-1 block w-full rounded-md border border-slate-200 px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-slate-900 dark:border-slate-700">
            </label>
            <label class="block text-xs text-slate-500 dark:text-slate-400">Currency
                <input type="text" wire:model.live="form.currency" placeholder="EUR" maxlength="3" class="mt-1 block w-full rounded-md border border-slate-200 px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-slate-900 dark:border-slate-700">
            </label>
            <label class="block text-xs text-slate-500 dark:text-slate-400">Direction
                <select wire:model.live="form.direction" class="mt-1 block w-full rounded-md border border-slate-200 px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-slate-900 dark:border-slate-700">
                    <option value="expense">Expense</option>
                    <option value="income">Income</option>
                </select>
            </label>
            <label class="block text-xs text-slate-500 dark:text-slate-400">Cadence
                <select wire:model.live="form.cadence" class="mt-1 block w-full rounded-md border border-slate-200 px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-slate-900 dark:border-slate-700">
                    <option value="weekly">Weekly</option>
                    <option value="monthly">Monthly</option>
                    <option value="quarterly">Quarterly</option>
                    <option value="yearly">Yearly</option>
                </select>
            </label>
            @break

        @case(\Modules\Forecasting\Public\Enums\ScenarioMutationKind::ChangeSeriesAmount->value)
            <label class="block text-xs text-slate-500 dark:text-slate-400">Series
                <select wire:model.live="form.seriesId" class="mt-1 block w-full rounded-md border border-slate-200 px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-slate-900 dark:border-slate-700">
                    <option value="">— pick a series —</option>
                    @foreach ($availableSeries as $opt)
                        <option value="{{ $opt['id'] }}">{{ $opt['name'] }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block text-xs text-slate-500 dark:text-slate-400">New amount
                <input type="text" wire:model.live="form.newAmount" placeholder="11,49" class="mt-1 block w-full rounded-md border border-slate-200 px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-slate-900 dark:border-slate-700">
            </label>
            @break

        @case(\Modules\Forecasting\Public\Enums\ScenarioMutationKind::ShiftSeriesDate->value)
            <label class="block text-xs text-slate-500 dark:text-slate-400">Series
                <select wire:model.live="form.seriesId" class="mt-1 block w-full rounded-md border border-slate-200 px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-slate-900 dark:border-slate-700">
                    <option value="">— pick a series —</option>
                    @foreach ($availableSeries as $opt)
                        <option value="{{ $opt['id'] }}">{{ $opt['name'] }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block text-xs text-slate-500 dark:text-slate-400">New next date
                <input type="date" wire:model.live="form.newNextDate" class="mt-1 block w-full rounded-md border border-slate-200 px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-slate-900 dark:border-slate-700">
            </label>
            <div class="text-xs text-slate-500 dark:text-slate-400">
                <p class="mb-1">Scope</p>
                <label class="inline-flex items-center gap-1">
                    <input type="radio" wire:model.live="form.scope" value="next">
                    <span>Just the next occurrence</span>
                </label>
                <label class="ml-3 inline-flex items-center gap-1">
                    <input type="radio" wire:model.live="form.scope" value="all_subsequent">
                    <span>All subsequent occurrences</span>
                </label>
            </div>
            @break
    @endswitch

    <div class="flex items-center gap-2 pt-2">
        <button
            type="button"
            wire:click="{{ $saveAction }}"
            class="rounded-md bg-emerald-600 px-3 py-1 text-sm text-white hover:bg-emerald-700 dark:hover:bg-emerald-400 dark:bg-emerald-500"
        >{{ $submitLabel ?? 'Save' }}</button>
        <button
            type="button"
            wire:click="{{ $cancelAction }}"
            class="rounded-md bg-slate-100 px-3 py-1 text-sm text-slate-700 hover:bg-slate-200 dark:hover:bg-slate-700 dark:bg-slate-800 dark:text-slate-300"
        >Cancel</button>
    </div>
</div>
