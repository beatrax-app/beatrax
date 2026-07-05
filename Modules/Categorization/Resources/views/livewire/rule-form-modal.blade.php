{{-- Rule form modal.

     Renders inside a Flux <flux:modal name="rule-form" dismissible>
     wrapper. Multi-condition / multi-action builder per
     13.4-UI-SPEC.md § Component Contract — combinator toggle,
     condition repeater (field-driven operator + value input(s)),
     action repeater (four action types), priority field, Save/Cancel.

     Mounted globally in app.blade.php; dispatched via the
     `rule-form:open` Livewire event (optional ruleId param hydrates
     edit mode). Save fires `rule-form:saved` then dispatches
     `modal-close` so any page can listen for the resulting refresh. --}}

<div>
    <flux:modal name="rule-form" dismissible>
        <div class="space-y-6">
            <flux:heading size="lg">
                {{ $isEditMode ? 'Edit rule' : 'New rule' }}
            </flux:heading>

            <form wire:submit="save" class="space-y-6">
                {{-- Combinator toggle --------------------------------- --}}
                <div class="view-toggle" role="group" aria-label="Condition combinator">
                    <button
                        type="button"
                        class="{{ $combinator === 'all' ? 'active' : '' }}"
                        aria-pressed="{{ $combinator === 'all' ? 'true' : 'false' }}"
                        wire:click="$set('combinator', 'all')"
                    >Match all conditions</button>
                    <button
                        type="button"
                        class="{{ $combinator === 'any' ? 'active' : '' }}"
                        aria-pressed="{{ $combinator === 'any' ? 'true' : 'false' }}"
                        wire:click="$set('combinator', 'any')"
                    >Match any condition</button>
                </div>

                {{-- Condition repeater ---------------------------------- --}}
                <div class="space-y-2">
                    @if ($errorConditions !== '')
                        <p class="text-sm text-rose-600 dark:text-rose-500">{{ $errorConditions }}</p>
                    @endif

                    @foreach ($conditions as $i => $condition)
                        @php
                            $valueType = \Modules\Categorization\Internal\Http\Livewire\RuleFormModal::valueTypeFor($condition['field']);
                            $opOptions = \Modules\Categorization\Internal\Http\Livewire\RuleFormModal::operatorOptionsFor($condition['field']);
                            $isBetween = $condition['op'] === 'between';
                        @endphp
                        <div class="rounded-md border border-slate-200 bg-slate-50 p-3 space-y-2 dark:bg-slate-900 dark:border-slate-700">
                            <p class="text-xs font-semibold text-slate-500 dark:text-slate-400">Condition {{ $i + 1 }}</p>
                            <div class="flex flex-wrap items-center gap-2">
                                <select
                                    wire:model.live="conditions.{{ $i }}.field"
                                    aria-label="Condition {{ $i + 1 }} field"
                                    class="inline-flex rounded-md border border-slate-200 bg-white px-2 py-1 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                                >
                                    <option value="merchant">merchant</option>
                                    <option value="description">description</option>
                                    <option value="counterparty">counterparty</option>
                                    <option value="amount">amount</option>
                                    <option value="date">date</option>
                                </select>

                                <select
                                    wire:model.live="conditions.{{ $i }}.op"
                                    aria-label="Condition {{ $i + 1 }} operator"
                                    class="inline-flex rounded-md border border-slate-200 bg-white px-2 py-1 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                                >
                                    @foreach ($opOptions as $opValue => $opLabel)
                                        <option value="{{ $opValue }}">{{ $opLabel }}</option>
                                    @endforeach
                                </select>

                                @if ($valueType === 'date')
                                    <input
                                        type="date"
                                        wire:model.lazy="conditions.{{ $i }}.value"
                                        aria-label="Condition {{ $i + 1 }} value{{ $isBetween ? ' (from)' : '' }}"
                                        class="rc-date rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                                    />
                                    @if ($isBetween)
                                        <span class="text-xs text-slate-500 dark:text-slate-400">to</span>
                                        <input
                                            type="date"
                                            wire:model.lazy="conditions.{{ $i }}.value2"
                                            aria-label="Condition {{ $i + 1 }} value (to)"
                                            class="rc-date rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                                        />
                                    @endif
                                @elseif ($valueType === 'amount')
                                    <input
                                        type="text"
                                        inputmode="decimal"
                                        placeholder="0,00"
                                        wire:model.lazy="conditions.{{ $i }}.value"
                                        aria-label="Condition {{ $i + 1 }} value{{ $isBetween ? ' (from)' : '' }}"
                                        class="w-28 rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-mono text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                                    />
                                    @if ($isBetween)
                                        <span class="text-xs text-slate-500 dark:text-slate-400">to</span>
                                        <input
                                            type="text"
                                            inputmode="decimal"
                                            placeholder="0,00"
                                            wire:model.lazy="conditions.{{ $i }}.value2"
                                            aria-label="Condition {{ $i + 1 }} value (to)"
                                            class="w-28 rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-mono text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                                        />
                                    @endif
                                @else
                                    <input
                                        type="text"
                                        placeholder="e.g. SPOTIFY"
                                        wire:model.lazy="conditions.{{ $i }}.value"
                                        aria-label="Condition {{ $i + 1 }} value"
                                        class="min-w-40 flex-1 rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-mono text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                                    />
                                @endif

                                <button
                                    type="button"
                                    wire:click="removeCondition({{ $i }})"
                                    aria-label="Remove condition"
                                    @disabled(count($conditions) <= 1)
                                    class="inline-flex items-center rounded-md px-2 py-1 text-sm font-medium text-rose-600 hover:bg-rose-50 disabled:cursor-not-allowed disabled:text-slate-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600 focus-visible:ring-offset-2 dark:text-rose-500 dark:hover:bg-rose-950 dark:disabled:text-slate-600"
                                >&times;</button>
                            </div>
                            @if (isset($conditionErrors[$i]))
                                <p class="text-sm text-rose-600 dark:text-rose-500">{{ $conditionErrors[$i] }}</p>
                            @endif
                        </div>
                    @endforeach

                    <button
                        type="button"
                        wire:click="addCondition"
                        class="pill-btn-ghost mt-2"
                    >+ Add condition</button>
                </div>

                {{-- Action repeater -------------------------------------- --}}
                <div class="space-y-2">
                    <p class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Then</p>

                    @if ($errorActions !== '')
                        <p class="text-sm text-rose-600 dark:text-rose-500">{{ $errorActions }}</p>
                    @endif

                    @foreach ($actions as $i => $action)
                        <div class="rounded-md border border-slate-200 bg-slate-50 p-3 space-y-2 dark:bg-slate-900 dark:border-slate-700">
                            <p class="text-xs font-semibold text-slate-500 dark:text-slate-400">Action {{ $i + 1 }}</p>
                            <div class="flex flex-wrap items-center gap-2">
                                <select
                                    wire:model.live="actions.{{ $i }}.type"
                                    aria-label="Action {{ $i + 1 }} type"
                                    class="inline-flex rounded-md border border-slate-200 bg-white px-2 py-1 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                                >
                                    <option value="category" @disabled($action['type'] !== 'category' && in_array('category', $usedActionTypes, true))>Category</option>
                                    <option value="counterparty" @disabled($action['type'] !== 'counterparty' && in_array('counterparty', $usedActionTypes, true))>Counterparty</option>
                                    <option value="note" @disabled($action['type'] !== 'note' && in_array('note', $usedActionTypes, true))>Note</option>
                                    <option value="tax_tag" @disabled($action['type'] !== 'tax_tag' && in_array('tax_tag', $usedActionTypes, true))>Tax tag</option>
                                </select>

                                @if ($action['type'] === 'category')
                                    <select
                                        wire:model.live="actions.{{ $i }}.category_id"
                                        aria-label="Assign category for action {{ $i + 1 }}"
                                        class="min-w-40 flex-1 rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                                    >
                                        <option value="">—</option>
                                        @foreach ($categories as $category)
                                            <option value="{{ $category->id }}">{{ $category->path }}</option>
                                        @endforeach
                                    </select>
                                @elseif ($action['type'] === 'counterparty')
                                    <select
                                        wire:model.live="actions.{{ $i }}.counterparty_id"
                                        aria-label="Reassign to counterparty for action {{ $i + 1 }}"
                                        class="min-w-40 flex-1 rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                                    >
                                        <option value="">—</option>
                                        @foreach ($counterparties as $counterparty)
                                            <option value="{{ $counterparty->id }}">{{ $counterparty->display_name }}</option>
                                        @endforeach
                                    </select>
                                @elseif ($action['type'] === 'note')
                                    <input
                                        type="text"
                                        placeholder="Note text…"
                                        wire:model.lazy="actions.{{ $i }}.note_text"
                                        aria-label="Note text for action {{ $i + 1 }}"
                                        class="min-w-40 flex-1 rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                                    />
                                    <div class="view-toggle" role="group" aria-label="Note mode for action {{ $i + 1 }}">
                                        <button
                                            type="button"
                                            class="{{ $action['note_mode'] === 'set' ? 'active' : '' }}"
                                            wire:click="$set('actions.{{ $i }}.note_mode', 'set')"
                                        >Set</button>
                                        <button
                                            type="button"
                                            class="{{ $action['note_mode'] === 'append' ? 'active' : '' }}"
                                            wire:click="$set('actions.{{ $i }}.note_mode', 'append')"
                                        >Append</button>
                                    </div>
                                @else
                                    <select
                                        wire:model.live="actions.{{ $i }}.deduction_category_id"
                                        aria-label="Deduction category for action {{ $i + 1 }}"
                                        class="min-w-40 flex-1 rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                                    >
                                        <option value="">—</option>
                                        @foreach ($deductionCategories as $deductionCategory)
                                            <option value="{{ $deductionCategory->id }}">{{ $deductionCategory->name }}</option>
                                        @endforeach
                                    </select>
                                @endif

                                <button
                                    type="button"
                                    wire:click="removeAction({{ $i }})"
                                    aria-label="Remove action"
                                    @disabled(count($actions) <= 1)
                                    class="inline-flex items-center rounded-md px-2 py-1 text-sm font-medium text-rose-600 hover:bg-rose-50 disabled:cursor-not-allowed disabled:text-slate-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600 focus-visible:ring-offset-2 dark:text-rose-500 dark:hover:bg-rose-950 dark:disabled:text-slate-600"
                                >&times;</button>
                            </div>

                            @if ($action['type'] === 'tax_tag')
                                <details class="text-xs text-slate-500 dark:text-slate-400">
                                    <summary class="cursor-pointer select-none">This year only ▾</summary>
                                    <div class="mt-2 flex items-center gap-2">
                                        <label class="inline-flex items-center gap-1">
                                            <input type="checkbox" wire:model.live="actions.{{ $i }}.year_override_enabled" />
                                            Override tax year
                                        </label>
                                        @if ($action['year_override_enabled'])
                                            <input
                                                type="number"
                                                wire:model.lazy="actions.{{ $i }}.year_override"
                                                aria-label="Tax year override for action {{ $i + 1 }}"
                                                class="w-24 rounded-md border border-slate-200 bg-white px-2 py-1 text-sm font-mono text-slate-900 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                                            />
                                        @endif
                                    </div>
                                </details>
                                <p class="text-xs text-amber-600 dark:text-amber-500">Tax tag actions apply on the next re-apply, not on the current import.</p>
                            @endif

                            @if (isset($actionErrors[$i]))
                                <p class="text-sm text-rose-600 dark:text-rose-500">{{ $actionErrors[$i] }}</p>
                            @endif
                        </div>
                    @endforeach

                    <button
                        type="button"
                        wire:click="addAction"
                        class="pill-btn-ghost mt-2"
                    >+ Add action</button>
                </div>

                {{-- Priority --------------------------------------------- --}}
                <div class="space-y-1">
                    <label for="rule-form-priority" class="block text-xs font-semibold text-slate-900 dark:text-slate-100">Priority</label>
                    <input
                        type="number"
                        id="rule-form-priority"
                        wire:model.lazy="priorityInput"
                        @if ($errorPriority !== '')
                            aria-invalid="true"
                            aria-describedby="rule-form-priority-error"
                        @endif
                        class="w-28 rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-mono text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-900 dark:border-slate-700 dark:text-slate-100"
                    />
                    <p class="text-xs text-slate-500 dark:text-slate-400">Lower numbers run first. Rules with no shared fields never conflict.</p>
                    @if ($errorPriority !== '')
                        <p id="rule-form-priority-error" class="text-sm text-rose-600 dark:text-rose-500">{{ $errorPriority }}</p>
                    @endif
                </div>

                @if ($errorGeneral !== '')
                    <p class="text-sm text-rose-600 dark:text-rose-500">{{ $errorGeneral }}</p>
                @endif

                <div class="flex items-center justify-end gap-2 pt-2">
                    <button
                        type="button"
                        wire:click="cancel"
                        class="pill-btn-ghost"
                    >Cancel</button>
                    <button
                        type="submit"
                        wire:loading.attr="disabled"
                        class="pill-btn-primary"
                    >
                        <span wire:loading.remove wire:target="save">{{ $isEditMode ? 'Save changes' : 'Save rule' }}</span>
                        <span wire:loading wire:target="save">Saving…</span>
                    </button>
                </div>
            </form>
        </div>
    </flux:modal>
</div>
