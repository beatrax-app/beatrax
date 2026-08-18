@use('Modules\Core\Public\Support\Lang')
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
            <label class="block text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('forecasting::scenario.form.series_to_cancel') }}
                <select wire:model.live="form.seriesId" class="mt-1 block w-full rounded-md border border-slate-200 px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-slate-900 dark:border-slate-700">
                    <option value="">{{ Lang::get('forecasting::scenario.form.pick_series') }}</option>
                    @foreach ($availableSeries as $opt)
                        <option value="{{ $opt['id'] }}">{{ $opt['name'] }}</option>
                    @endforeach
                </select>
            </label>
            @break

        @case(\Modules\Forecasting\Public\Enums\ScenarioMutationKind::AddOneOff->value)
            <div class="block text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('forecasting::scenario.form.date') }}
                <x-core::date-input class="mt-1" wire:model.live="form.date" :aria-label="Lang::get('forecasting::scenario.form.date')" />
            </div>
            <label class="block text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('forecasting::scenario.form.amount') }}
                <input type="text" wire:model.live="form.amount" placeholder="50,00" class="mt-1 block w-full rounded-md border border-slate-200 px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-slate-900 dark:border-slate-700">
            </label>
            <label class="block text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('forecasting::scenario.form.currency') }}
                <input type="text" wire:model.live="form.currency" placeholder="EUR" maxlength="3" class="mt-1 block w-full rounded-md border border-slate-200 px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-slate-900 dark:border-slate-700">
            </label>
            <label class="block text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('forecasting::scenario.form.direction') }}
                <select wire:model.live="form.direction" class="mt-1 block w-full rounded-md border border-slate-200 px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-slate-900 dark:border-slate-700">
                    <option value="expense">{{ Lang::get('forecasting::scenario.form.expense_long') }}</option>
                    <option value="income">{{ Lang::get('forecasting::scenario.form.income_long') }}</option>
                </select>
            </label>
            <label class="block text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('forecasting::scenario.form.note') }}
                <input type="text" wire:model.live="form.note" class="mt-1 block w-full rounded-md border border-slate-200 px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-slate-900 dark:border-slate-700">
            </label>
            @break

        @case(\Modules\Forecasting\Public\Enums\ScenarioMutationKind::AddRecurring->value)
            <div class="block text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('forecasting::scenario.form.start_date') }}
                <x-core::date-input class="mt-1" wire:model.live="form.startDate" :aria-label="Lang::get('forecasting::scenario.form.start_date')" />
            </div>
            <label class="block text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('forecasting::scenario.form.amount') }}
                <input type="text" wire:model.live="form.amount" placeholder="15,00" class="mt-1 block w-full rounded-md border border-slate-200 px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-slate-900 dark:border-slate-700">
            </label>
            <label class="block text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('forecasting::scenario.form.currency') }}
                <input type="text" wire:model.live="form.currency" placeholder="EUR" maxlength="3" class="mt-1 block w-full rounded-md border border-slate-200 px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-slate-900 dark:border-slate-700">
            </label>
            <label class="block text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('forecasting::scenario.form.direction') }}
                <select wire:model.live="form.direction" class="mt-1 block w-full rounded-md border border-slate-200 px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-slate-900 dark:border-slate-700">
                    <option value="expense">{{ Lang::get('forecasting::scenario.form.expense') }}</option>
                    <option value="income">{{ Lang::get('forecasting::scenario.form.income') }}</option>
                </select>
            </label>
            <label class="block text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('forecasting::scenario.form.cadence') }}
                <select wire:model.live="form.cadence" class="mt-1 block w-full rounded-md border border-slate-200 px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-slate-900 dark:border-slate-700">
                    <option value="weekly">{{ Lang::get('forecasting::scenario.form.cadence_weekly') }}</option>
                    <option value="monthly">{{ Lang::get('forecasting::scenario.form.cadence_monthly') }}</option>
                    <option value="quarterly">{{ Lang::get('forecasting::scenario.form.cadence_quarterly') }}</option>
                    <option value="yearly">{{ Lang::get('forecasting::scenario.form.cadence_yearly') }}</option>
                </select>
            </label>
            @break

        @case(\Modules\Forecasting\Public\Enums\ScenarioMutationKind::ChangeSeriesAmount->value)
            <label class="block text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('forecasting::scenario.form.series') }}
                <select wire:model.live="form.seriesId" class="mt-1 block w-full rounded-md border border-slate-200 px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-slate-900 dark:border-slate-700">
                    <option value="">{{ Lang::get('forecasting::scenario.form.pick_series') }}</option>
                    @foreach ($availableSeries as $opt)
                        <option value="{{ $opt['id'] }}">{{ $opt['name'] }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('forecasting::scenario.form.new_amount') }}
                <input type="text" wire:model.live="form.newAmount" placeholder="11,49" class="mt-1 block w-full rounded-md border border-slate-200 px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-slate-900 dark:border-slate-700">
            </label>
            @break

        @case(\Modules\Forecasting\Public\Enums\ScenarioMutationKind::ShiftSeriesDate->value)
            <label class="block text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('forecasting::scenario.form.series') }}
                <select wire:model.live="form.seriesId" class="mt-1 block w-full rounded-md border border-slate-200 px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-slate-900 dark:border-slate-700">
                    <option value="">{{ Lang::get('forecasting::scenario.form.pick_series') }}</option>
                    @foreach ($availableSeries as $opt)
                        <option value="{{ $opt['id'] }}">{{ $opt['name'] }}</option>
                    @endforeach
                </select>
            </label>
            <div class="block text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('forecasting::scenario.form.new_next_date') }}
                <x-core::date-input class="mt-1" wire:model.live="form.newNextDate" :aria-label="Lang::get('forecasting::scenario.form.new_next_date')" />
            </div>
            <div class="text-xs text-slate-500 dark:text-slate-400">
                <p class="mb-1">{{ Lang::get('forecasting::scenario.form.scope') }}</p>
                <label class="inline-flex items-center gap-1">
                    <input type="radio" wire:model.live="form.scope" value="next">
                    <span>{{ Lang::get('forecasting::scenario.form.scope_next') }}</span>
                </label>
                <label class="ml-3 inline-flex items-center gap-1">
                    <input type="radio" wire:model.live="form.scope" value="all_subsequent">
                    <span>{{ Lang::get('forecasting::scenario.form.scope_all') }}</span>
                </label>
            </div>
            @break
    @endswitch

    <div class="flex items-center gap-2 pt-2">
        <button
            type="button"
            wire:click="{{ $saveAction }}"
            class="rounded-md bg-emerald-600 px-3 py-1 text-sm text-white hover:bg-emerald-700 dark:hover:bg-emerald-400 dark:bg-emerald-500"
        >{{ $submitLabel ?? Lang::get('forecasting::scenario.save') }}</button>
        <button
            type="button"
            wire:click="{{ $cancelAction }}"
            class="rounded-md bg-slate-100 px-3 py-1 text-sm text-slate-700 hover:bg-slate-200 dark:hover:bg-slate-700 dark:bg-slate-800 dark:text-slate-300"
        >{{ Lang::get('forecasting::scenario.cancel') }}</button>
    </div>
</div>
