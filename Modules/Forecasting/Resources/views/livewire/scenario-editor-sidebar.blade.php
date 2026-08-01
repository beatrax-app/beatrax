@use('Modules\Core\Public\Support\Lang')
{{--
    Scenario editor sidebar — the right-rail Livewire SFC mounted
    inside ForecastPage when a saved scenario is active.

    Sections in render order:
      1. Header — scenario name + Rename text-link + Delete destructive
         text-link.
      2. Mutations list — one card per mutation with kind-specific
         summary + Edit + Remove chips.
      3. Add-mutation chip → five-option chooser → per-kind inline
         form.
--}}
<aside aria-label="{{ Lang::get('forecasting::scenario.editor_aria', ['name' => $scenarioName]) }}" class="space-y-4">
    {{-- Header. --}}
    <div>
        @if ($renamingScenario)
            <div class="flex flex-wrap items-center gap-2">
                <input
                    type="text"
                    wire:model.live="renameInput"
                    wire:keydown.enter.prevent="saveRename"
                    aria-label="{{ Lang::get('forecasting::scenario.rename_aria') }}"
                    class="block w-full rounded-md border border-slate-200 px-2 py-1 text-base font-semibold focus:outline-none focus:ring-2 focus:ring-slate-900 dark:border-slate-700"
                >
                <button
                    type="button"
                    wire:click="saveRename"
                    class="rounded-md bg-emerald-600 px-3 py-1 text-sm text-white hover:bg-emerald-700 dark:hover:bg-emerald-400 dark:bg-emerald-500"
                >{{ Lang::get('forecasting::scenario.save') }}</button>
                <button
                    type="button"
                    wire:click="cancelRename"
                    class="rounded-md bg-slate-100 px-3 py-1 text-sm text-slate-700 hover:bg-slate-200 dark:hover:bg-slate-700 dark:bg-slate-800 dark:text-slate-300"
                >{{ Lang::get('forecasting::scenario.cancel') }}</button>
            </div>
            @if ($renameError !== null)
                <p class="mt-1 text-xs text-rose-700 dark:text-rose-500" role="alert">{{ $renameError }}</p>
            @endif
        @else
            <div class="flex items-baseline justify-between gap-2">
                <h2 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ $scenarioName }}</h2>
                <div class="flex items-center gap-2 text-xs">
                    <button
                        type="button"
                        wire:click="startRename"
                        class="text-slate-500 underline-offset-2 hover:text-slate-900 hover:underline dark:hover:text-slate-100 dark:text-slate-400"
                    >{{ Lang::get('forecasting::scenario.rename') }}</button>
                    @if ($confirmingDeleteScenario === $scenarioId)
                        <button
                            type="button"
                            wire:click="deleteScenario"
                            class="text-rose-700 underline-offset-2 hover:underline dark:text-rose-500"
                        >{{ Lang::get('forecasting::scenario.confirm_delete') }}</button>
                        <button
                            type="button"
                            wire:click="cancelDeleteScenario"
                            class="text-slate-500 underline-offset-2 hover:text-slate-900 hover:underline dark:hover:text-slate-100 dark:text-slate-400"
                        >{{ Lang::get('forecasting::scenario.cancel') }}</button>
                    @else
                        <button
                            type="button"
                            wire:click="confirmDeleteScenario"
                            class="text-rose-700 underline-offset-2 hover:underline dark:text-rose-500"
                        >{{ Lang::get('forecasting::scenario.delete_scenario') }}</button>
                    @endif
                </div>
            </div>
            @if ($scenarioDescription !== null && $scenarioDescription !== '')
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $scenarioDescription }}</p>
            @endif
        @endif
    </div>

    {{-- Mutations list. --}}
    <div>
        <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('forecasting::scenario.mutations_count', ['count' => count($mutations)]) }}</h3>
        @if (count($mutations) === 0)
            <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('forecasting::scenario.no_mutations') }}</p>
        @else
            <ul class="mt-2 space-y-2">
                @foreach ($mutations as $m)
                    <li class="rounded-md border border-slate-200 bg-white p-3 dark:bg-slate-950 dark:border-slate-700">
                        @if ($editingMutationId === $m['id'])
                            <div class="space-y-2">
                                <p class="text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('forecasting::scenario.editing', ['kind' => $m['kind']]) }}</p>
                                @include('forecasting::livewire.partials.scenario-mutation-form', [
                                    'form' => $form,
                                    'kind' => $selectedKind ?? $m['kind'],
                                    'availableSeries' => $availableSeries,
                                    'saveAction' => 'saveEditMutation',
                                    'cancelAction' => 'cancelEditMutation',
                                    'submitLabel' => Lang::get('forecasting::scenario.save_changes'),
                                ])
                                @if ($formError !== null)
                                    <p class="text-xs text-rose-700 dark:text-rose-500" role="alert">{{ $formError }}</p>
                                @endif
                            </div>
                        @else
                            <div class="flex items-center justify-between gap-2">
                                <p class="text-sm text-slate-900 dark:text-slate-100">{{ $m['summary'] }}</p>
                                <div class="flex items-center gap-2 text-xs">
                                    <button
                                        type="button"
                                        wire:click="editMutation({{ $m['id'] }})"
                                        class="text-slate-500 underline-offset-2 hover:text-slate-900 hover:underline dark:hover:text-slate-100 dark:text-slate-400"
                                    >{{ Lang::get('forecasting::scenario.edit') }}</button>
                                    <button
                                        type="button"
                                        wire:click="removeMutation({{ $m['id'] }})"
                                        class="text-rose-700 underline-offset-2 hover:underline dark:text-rose-500"
                                    >{{ Lang::get('forecasting::scenario.remove') }}</button>
                                </div>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    {{-- Add chooser + per-kind inline form. --}}
    <div>
        @if (! $addingMutation && $editingMutationId === null)
            <button
                type="button"
                wire:click="startAddMutation"
                class="rounded-md bg-emerald-600 px-3 py-1 text-sm text-white hover:bg-emerald-700 dark:hover:bg-emerald-400 dark:bg-emerald-500"
            >{{ Lang::get('forecasting::scenario.add_mutation') }}</button>
        @elseif ($addingMutation)
            @if ($selectedKind === null)
                <p class="text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('forecasting::scenario.pick_kind') }}</p>
                <div class="mt-2 grid grid-cols-1 gap-2">
                    <button type="button" wire:click="selectKind('{{ \Modules\Forecasting\Public\Enums\ScenarioMutationKind::CancelSeries->value }}')" class="rounded-md border border-slate-200 bg-white p-2 text-left text-sm hover:bg-slate-50 dark:hover:bg-slate-900 dark:bg-slate-950 dark:border-slate-700">
                        <span class="font-medium text-slate-900 dark:text-slate-100">{{ Lang::get('forecasting::scenario.kind.cancel_series.title') }}</span>
                        <span class="block text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('forecasting::scenario.kind.cancel_series.desc') }}</span>
                    </button>
                    <button type="button" wire:click="selectKind('{{ \Modules\Forecasting\Public\Enums\ScenarioMutationKind::AddOneOff->value }}')" class="rounded-md border border-slate-200 bg-white p-2 text-left text-sm hover:bg-slate-50 dark:hover:bg-slate-900 dark:bg-slate-950 dark:border-slate-700">
                        <span class="font-medium text-slate-900 dark:text-slate-100">{{ Lang::get('forecasting::scenario.kind.add_one_off.title') }}</span>
                        <span class="block text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('forecasting::scenario.kind.add_one_off.desc') }}</span>
                    </button>
                    <button type="button" wire:click="selectKind('{{ \Modules\Forecasting\Public\Enums\ScenarioMutationKind::AddRecurring->value }}')" class="rounded-md border border-slate-200 bg-white p-2 text-left text-sm hover:bg-slate-50 dark:hover:bg-slate-900 dark:bg-slate-950 dark:border-slate-700">
                        <span class="font-medium text-slate-900 dark:text-slate-100">{{ Lang::get('forecasting::scenario.kind.add_recurring.title') }}</span>
                        <span class="block text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('forecasting::scenario.kind.add_recurring.desc') }}</span>
                    </button>
                    <button type="button" wire:click="selectKind('{{ \Modules\Forecasting\Public\Enums\ScenarioMutationKind::ChangeSeriesAmount->value }}')" class="rounded-md border border-slate-200 bg-white p-2 text-left text-sm hover:bg-slate-50 dark:hover:bg-slate-900 dark:bg-slate-950 dark:border-slate-700">
                        <span class="font-medium text-slate-900 dark:text-slate-100">{{ Lang::get('forecasting::scenario.kind.change_series_amount.title') }}</span>
                        <span class="block text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('forecasting::scenario.kind.change_series_amount.desc') }}</span>
                    </button>
                    <button type="button" wire:click="selectKind('{{ \Modules\Forecasting\Public\Enums\ScenarioMutationKind::ShiftSeriesDate->value }}')" class="rounded-md border border-slate-200 bg-white p-2 text-left text-sm hover:bg-slate-50 dark:hover:bg-slate-900 dark:bg-slate-950 dark:border-slate-700">
                        <span class="font-medium text-slate-900 dark:text-slate-100">{{ Lang::get('forecasting::scenario.kind.shift_series_date.title') }}</span>
                        <span class="block text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('forecasting::scenario.kind.shift_series_date.desc') }}</span>
                    </button>
                </div>
                <button
                    type="button"
                    wire:click="cancelAddMutation"
                    class="mt-2 text-xs text-slate-500 underline-offset-2 hover:text-slate-900 hover:underline dark:hover:text-slate-100 dark:text-slate-400"
                >{{ Lang::get('forecasting::scenario.cancel') }}</button>
            @else
                @include('forecasting::livewire.partials.scenario-mutation-form', [
                    'form' => $form,
                    'kind' => $selectedKind,
                    'availableSeries' => $availableSeries,
                    'saveAction' => 'saveAddMutation',
                    'cancelAction' => 'cancelAddMutation',
                    'submitLabel' => Lang::get('forecasting::scenario.add_to_scenario'),
                ])
                @if ($formError !== null)
                    <p class="mt-1 text-xs text-rose-700 dark:text-rose-500" role="alert">{{ $formError }}</p>
                @endif
            @endif
        @endif
    </div>
</aside>
