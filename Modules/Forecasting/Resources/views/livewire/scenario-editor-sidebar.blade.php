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
<aside role="complementary" aria-label="Scenario editor — {{ $scenarioName }}" class="space-y-4">
    {{-- Header. --}}
    <div>
        @if ($renamingScenario)
            <div class="flex flex-wrap items-center gap-2">
                <input
                    type="text"
                    wire:model.live="renameInput"
                    wire:keydown.enter.prevent="saveRename"
                    aria-label="Rename scenario"
                    class="block w-full rounded-md border border-slate-200 px-2 py-1 text-base font-semibold focus:outline-none focus:ring-2 focus:ring-slate-900"
                >
                <button
                    type="button"
                    wire:click="saveRename"
                    class="rounded-md bg-emerald-600 px-3 py-1 text-sm text-white hover:bg-emerald-700"
                >Save</button>
                <button
                    type="button"
                    wire:click="cancelRename"
                    class="rounded-md bg-slate-100 px-3 py-1 text-sm text-slate-700 hover:bg-slate-200"
                >Cancel</button>
            </div>
            @if ($renameError !== null)
                <p class="mt-1 text-xs text-rose-700" role="alert">{{ $renameError }}</p>
            @endif
        @else
            <div class="flex items-baseline justify-between gap-2">
                <h2 class="text-lg font-semibold text-slate-900">{{ $scenarioName }}</h2>
                <div class="flex items-center gap-2 text-xs">
                    <button
                        type="button"
                        wire:click="startRename"
                        class="text-slate-500 underline-offset-2 hover:text-slate-900 hover:underline"
                    >Rename</button>
                    @if ($confirmingDeleteScenario === $scenarioId)
                        <button
                            type="button"
                            wire:click="deleteScenario"
                            class="text-rose-700 underline-offset-2 hover:underline"
                        >Confirm delete</button>
                        <button
                            type="button"
                            wire:click="cancelDeleteScenario"
                            class="text-slate-500 underline-offset-2 hover:text-slate-900 hover:underline"
                        >Cancel</button>
                    @else
                        <button
                            type="button"
                            wire:click="confirmDeleteScenario"
                            class="text-rose-700 underline-offset-2 hover:underline"
                        >Delete scenario</button>
                    @endif
                </div>
            </div>
            @if ($scenarioDescription !== null && $scenarioDescription !== '')
                <p class="mt-1 text-xs text-slate-500">{{ $scenarioDescription }}</p>
            @endif
        @endif
    </div>

    {{-- Mutations list. --}}
    <div>
        <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Mutations ({{ count($mutations) }})</h3>
        @if (count($mutations) === 0)
            <p class="mt-2 text-xs text-slate-500">No mutations yet. Add one below to see how this scenario compares to your baseline.</p>
        @else
            <ul class="mt-2 space-y-2">
                @foreach ($mutations as $m)
                    <li class="rounded-md border border-slate-200 bg-white p-3">
                        @if ($editingMutationId === $m['id'])
                            <div class="space-y-2">
                                <p class="text-xs text-slate-500">Editing — {{ $m['kind'] }}</p>
                                @include('forecasting::livewire.partials.scenario-mutation-form', [
                                    'form' => $form,
                                    'kind' => $selectedKind ?? $m['kind'],
                                    'availableSeries' => $availableSeries,
                                    'saveAction' => 'saveEditMutation',
                                    'cancelAction' => 'cancelEditMutation',
                                    'submitLabel' => 'Save changes',
                                ])
                                @if ($formError !== null)
                                    <p class="text-xs text-rose-700" role="alert">{{ $formError }}</p>
                                @endif
                            </div>
                        @else
                            <div class="flex items-center justify-between gap-2">
                                <p class="text-sm text-slate-900">{{ $m['summary'] }}</p>
                                <div class="flex items-center gap-2 text-xs">
                                    <button
                                        type="button"
                                        wire:click="editMutation({{ $m['id'] }})"
                                        class="text-slate-500 underline-offset-2 hover:text-slate-900 hover:underline"
                                    >Edit</button>
                                    <button
                                        type="button"
                                        wire:click="removeMutation({{ $m['id'] }})"
                                        class="text-rose-700 underline-offset-2 hover:underline"
                                    >Remove</button>
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
                class="rounded-md bg-emerald-600 px-3 py-1 text-sm text-white hover:bg-emerald-700"
            >+ Add mutation</button>
        @elseif ($addingMutation)
            @if ($selectedKind === null)
                <p class="text-xs text-slate-500">Pick a mutation kind:</p>
                <div class="mt-2 grid grid-cols-1 gap-2">
                    <button type="button" wire:click="selectKind('cancel_series')" class="rounded-md border border-slate-200 bg-white p-2 text-left text-sm hover:bg-slate-50">
                        <span class="font-medium text-slate-900">Cancel a series</span>
                        <span class="block text-xs text-slate-500">Drop every projected occurrence of an approved series.</span>
                    </button>
                    <button type="button" wire:click="selectKind('add_one_off')" class="rounded-md border border-slate-200 bg-white p-2 text-left text-sm hover:bg-slate-50">
                        <span class="font-medium text-slate-900">Add a one-off charge or credit</span>
                        <span class="block text-xs text-slate-500">A single hypothetical event on a specific date.</span>
                    </button>
                    <button type="button" wire:click="selectKind('add_recurring')" class="rounded-md border border-slate-200 bg-white p-2 text-left text-sm hover:bg-slate-50">
                        <span class="font-medium text-slate-900">Add a recurring series</span>
                        <span class="block text-xs text-slate-500">A hypothetical new subscription or income stream.</span>
                    </button>
                    <button type="button" wire:click="selectKind('change_series_amount')" class="rounded-md border border-slate-200 bg-white p-2 text-left text-sm hover:bg-slate-50">
                        <span class="font-medium text-slate-900">Change a series amount</span>
                        <span class="block text-xs text-slate-500">Model a price hike or drop on an existing series.</span>
                    </button>
                    <button type="button" wire:click="selectKind('shift_series_date')" class="rounded-md border border-slate-200 bg-white p-2 text-left text-sm hover:bg-slate-50">
                        <span class="font-medium text-slate-900">Shift a series date</span>
                        <span class="block text-xs text-slate-500">Move the next or all subsequent occurrences forward.</span>
                    </button>
                </div>
                <button
                    type="button"
                    wire:click="cancelAddMutation"
                    class="mt-2 text-xs text-slate-500 underline-offset-2 hover:text-slate-900 hover:underline"
                >Cancel</button>
            @else
                @include('forecasting::livewire.partials.scenario-mutation-form', [
                    'form' => $form,
                    'kind' => $selectedKind,
                    'availableSeries' => $availableSeries,
                    'saveAction' => 'saveAddMutation',
                    'cancelAction' => 'cancelAddMutation',
                    'submitLabel' => 'Add to scenario',
                ])
                @if ($formError !== null)
                    <p class="mt-1 text-xs text-rose-700" role="alert">{{ $formError }}</p>
                @endif
            @endif
        @endif
    </div>
</aside>
