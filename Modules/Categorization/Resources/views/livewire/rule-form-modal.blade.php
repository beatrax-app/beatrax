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

@use('Modules\Core\Public\Support\Lang')
<div>
    <flux:modal name="rule-form" dismissible>
        <div class="space-y-6">
            <flux:heading size="lg">
                {{ $isEditMode ? Lang::get('categorization::rule_form.heading_edit') : Lang::get('categorization::rule_form.heading_new') }}
            </flux:heading>

            <form wire:submit="save" class="space-y-6">
                {{-- Combinator toggle --------------------------------- --}}
                <div class="view-toggle" role="group" aria-label="{{ Lang::get('categorization::rule_form.combinator_aria') }}">
                    <button
                        type="button"
                        class="{{ $combinator === 'all' ? 'active' : '' }}"
                        aria-pressed="{{ $combinator === 'all' ? 'true' : 'false' }}"
                        wire:click="$set('combinator', 'all')"
                    >{{ Lang::get('categorization::rule_form.match_all') }}</button>
                    <button
                        type="button"
                        class="{{ $combinator === 'any' ? 'active' : '' }}"
                        aria-pressed="{{ $combinator === 'any' ? 'true' : 'false' }}"
                        wire:click="$set('combinator', 'any')"
                    >{{ Lang::get('categorization::rule_form.match_any') }}</button>
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
                            <p class="text-xs font-semibold text-slate-500 dark:text-slate-400">{{ Lang::get('categorization::rule_form.condition_label', ['number' => $i + 1]) }}</p>
                            <div class="flex flex-wrap items-center gap-2">
                                <select
                                    wire:model.live="conditions.{{ $i }}.field"
                                    aria-label="{{ Lang::get('categorization::rule_form.condition_field_aria', ['number' => $i + 1]) }}"
                                    class="inline-flex rounded-md border border-slate-200 bg-white px-2 py-1 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                                >
                                    <option value="description">{{ Lang::get('categorization::rule_form.field_description') }}</option>
                                    <option value="counterparty">{{ Lang::get('categorization::rule_form.field_counterparty') }}</option>
                                    <option value="amount">{{ Lang::get('categorization::rule_form.field_amount') }}</option>
                                    <option value="date">{{ Lang::get('categorization::rule_form.field_date') }}</option>
                                </select>

                                <select
                                    wire:model.live="conditions.{{ $i }}.op"
                                    aria-label="{{ Lang::get('categorization::rule_form.condition_operator_aria', ['number' => $i + 1]) }}"
                                    class="inline-flex rounded-md border border-slate-200 bg-white px-2 py-1 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                                >
                                    @foreach ($opOptions as $opValue => $opLabel)
                                        <option value="{{ $opValue }}">{{ $opLabel }}</option>
                                    @endforeach
                                </select>

                                @if ($valueType === 'date')
                                    <x-core::date-input
                                        wire:model.lazy="conditions.{{ $i }}.value"
                                        :aria-label="$isBetween ? Lang::get('categorization::rule_form.condition_value_from_aria', ['number' => $i + 1]) : Lang::get('categorization::rule_form.condition_value_aria', ['number' => $i + 1])"
                                    />
                                    @if ($isBetween)
                                        <span class="text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('categorization::rule_form.to') }}</span>
                                        <x-core::date-input
                                            wire:model.lazy="conditions.{{ $i }}.value2"
                                            :aria-label="Lang::get('categorization::rule_form.condition_value_to_aria', ['number' => $i + 1])"
                                        />
                                    @endif
                                @elseif ($valueType === 'amount')
                                    <input
                                        type="text"
                                        inputmode="decimal"
                                        placeholder="{{ Lang::get('categorization::rule_form.amount_placeholder') }}"
                                        wire:model.lazy="conditions.{{ $i }}.value"
                                        aria-label="{{ $isBetween ? Lang::get('categorization::rule_form.condition_value_from_aria', ['number' => $i + 1]) : Lang::get('categorization::rule_form.condition_value_aria', ['number' => $i + 1]) }}"
                                        class="w-28 rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-mono text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                                    />
                                    @if ($isBetween)
                                        <span class="text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('categorization::rule_form.to') }}</span>
                                        <input
                                            type="text"
                                            inputmode="decimal"
                                            placeholder="{{ Lang::get('categorization::rule_form.amount_placeholder') }}"
                                            wire:model.lazy="conditions.{{ $i }}.value2"
                                            aria-label="{{ Lang::get('categorization::rule_form.condition_value_to_aria', ['number' => $i + 1]) }}"
                                            class="w-28 rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-mono text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                                        />
                                    @endif
                                @else
                                    <input
                                        type="text"
                                        placeholder="{{ Lang::get('categorization::rule_form.text_placeholder') }}"
                                        wire:model.lazy="conditions.{{ $i }}.value"
                                        aria-label="{{ Lang::get('categorization::rule_form.condition_value_aria', ['number' => $i + 1]) }}"
                                        class="min-w-40 flex-1 rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-mono text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                                    />
                                @endif

                                <x-core::emoji-action
                                    :label="Lang::get('categorization::rule_form.remove_condition')"
                                    tone="danger"
                                    wire:click="removeCondition({{ $i }})"
                                    :disabled="count($conditions) <= 1"
                                >🗑️</x-core::emoji-action>
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
                    >{{ Lang::get('categorization::rule_form.add_condition') }}</button>
                </div>

                {{-- Action repeater -------------------------------------- --}}
                <div class="space-y-2">
                    <p class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">{{ Lang::get('categorization::rule_form.then') }}</p>

                    @if ($errorActions !== '')
                        <p class="text-sm text-rose-600 dark:text-rose-500">{{ $errorActions }}</p>
                    @endif

                    @foreach ($actions as $i => $action)
                        <div class="rounded-md border border-slate-200 bg-slate-50 p-3 space-y-2 dark:bg-slate-900 dark:border-slate-700">
                            <p class="text-xs font-semibold text-slate-500 dark:text-slate-400">{{ Lang::get('categorization::rule_form.action_label', ['number' => $i + 1]) }}</p>
                            <div class="flex flex-wrap items-center gap-2">
                                <select
                                    wire:model.live="actions.{{ $i }}.type"
                                    aria-label="{{ Lang::get('categorization::rule_form.action_type_aria', ['number' => $i + 1]) }}"
                                    class="inline-flex rounded-md border border-slate-200 bg-white px-2 py-1 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                                >
                                    <option value="category" @disabled($action['type'] !== 'category' && in_array('category', $usedActionTypes, true))>{{ Lang::get('categorization::rule_form.action_category') }}</option>
                                    <option value="counterparty" @disabled($action['type'] !== 'counterparty' && in_array('counterparty', $usedActionTypes, true))>{{ Lang::get('categorization::rule_form.action_counterparty') }}</option>
                                    <option value="note" @disabled($action['type'] !== 'note' && in_array('note', $usedActionTypes, true))>{{ Lang::get('categorization::rule_form.action_note') }}</option>
                                    <option value="tax_tag" @disabled($action['type'] !== 'tax_tag' && in_array('tax_tag', $usedActionTypes, true))>{{ Lang::get('categorization::rule_form.action_tax_tag') }}</option>
                                </select>

                                @if ($action['type'] === 'category')
                                    <select
                                        wire:model.live="actions.{{ $i }}.category_id"
                                        aria-label="{{ Lang::get('categorization::rule_form.assign_category_aria', ['number' => $i + 1]) }}"
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
                                        aria-label="{{ Lang::get('categorization::rule_form.reassign_counterparty_aria', ['number' => $i + 1]) }}"
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
                                        placeholder="{{ Lang::get('categorization::rule_form.note_placeholder') }}"
                                        wire:model.lazy="actions.{{ $i }}.note_text"
                                        aria-label="{{ Lang::get('categorization::rule_form.note_text_aria', ['number' => $i + 1]) }}"
                                        class="min-w-40 flex-1 rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                                    />
                                    <div class="view-toggle" role="group" aria-label="{{ Lang::get('categorization::rule_form.note_mode_aria', ['number' => $i + 1]) }}">
                                        <button
                                            type="button"
                                            class="{{ $action['note_mode'] === 'set' ? 'active' : '' }}"
                                            wire:click="$set('actions.{{ $i }}.note_mode', 'set')"
                                        >{{ Lang::get('categorization::rule_form.note_set') }}</button>
                                        <button
                                            type="button"
                                            class="{{ $action['note_mode'] === 'append' ? 'active' : '' }}"
                                            wire:click="$set('actions.{{ $i }}.note_mode', 'append')"
                                        >{{ Lang::get('categorization::rule_form.note_append') }}</button>
                                    </div>
                                @else
                                    <select
                                        wire:model.live="actions.{{ $i }}.deduction_category_id"
                                        aria-label="{{ Lang::get('categorization::rule_form.deduction_category_aria', ['number' => $i + 1]) }}"
                                        class="min-w-40 flex-1 rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                                    >
                                        <option value="">—</option>
                                        @foreach ($deductionCategories as $deductionCategory)
                                            <option value="{{ $deductionCategory->id }}">{{ $deductionCategory->name }}</option>
                                        @endforeach
                                    </select>
                                @endif

                                <x-core::emoji-action
                                    :label="Lang::get('categorization::rule_form.remove_action')"
                                    tone="danger"
                                    wire:click="removeAction({{ $i }})"
                                    :disabled="count($actions) <= 1"
                                >🗑️</x-core::emoji-action>
                            </div>

                            @if ($action['type'] === 'tax_tag')
                                <details class="text-xs text-slate-500 dark:text-slate-400">
                                    <summary class="cursor-pointer select-none">{{ Lang::get('categorization::rule_form.this_year_only') }}</summary>
                                    <div class="mt-2 flex items-center gap-2">
                                        <label class="inline-flex items-center gap-1">
                                            <input type="checkbox" wire:model.live="actions.{{ $i }}.year_override_enabled" />
                                            {{ Lang::get('categorization::rule_form.override_tax_year') }}
                                        </label>
                                        @if ($action['year_override_enabled'])
                                            <input
                                                type="number"
                                                wire:model.lazy="actions.{{ $i }}.year_override"
                                                aria-label="{{ Lang::get('categorization::rule_form.tax_year_override_aria', ['number' => $i + 1]) }}"
                                                class="w-24 rounded-md border border-slate-200 bg-white px-2 py-1 text-sm font-mono text-slate-900 dark:bg-slate-950 dark:border-slate-700 dark:text-slate-100"
                                            />
                                        @endif
                                    </div>
                                </details>
                                <p class="text-xs text-amber-600 dark:text-amber-500">{{ Lang::get('categorization::rule_form.tax_tag_note') }}</p>
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
                    >{{ Lang::get('categorization::rule_form.add_action') }}</button>
                </div>

                {{-- Priority --------------------------------------------- --}}
                <div class="space-y-1">
                    <label for="rule-form-priority" class="block text-xs font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('categorization::rule_form.priority') }}</label>
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
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('categorization::rule_form.priority_help') }}</p>
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
                    >{{ Lang::get('categorization::rule_form.cancel') }}</button>
                    <button
                        type="submit"
                        wire:loading.attr="disabled"
                        class="pill-btn-primary"
                    >
                        <span wire:loading.remove wire:target="save">{{ $isEditMode ? Lang::get('categorization::rule_form.save_changes') : Lang::get('categorization::rule_form.save_rule') }}</span>
                        <span wire:loading wire:target="save">{{ Lang::get('categorization::rule_form.saving') }}</span>
                    </button>
                </div>
            </form>
        </div>
    </flux:modal>
</div>
