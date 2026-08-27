{{-- /rules page.

     Renders the user's categorization-rule list as a calm table with
     Priority / Conditions / Actions / Hits / Created columns — rows
     render priority-asc, id-asc, the same order RuleEngine::match()
     executes them in, so the table visually *is* the execution order
     (13.4-UI-SPEC.md § Component Contract). The `New rule` button +
     per-row Edit chip dispatch the global `rule-form:open` Livewire
     event so the globally-mounted RuleFormModal SFC handles the form.
     The per-row Delete chip collapses into a two-step inline
     confirmation. A ghost "Re-apply rules to history" trigger sits
     beside "New rule" and drives a wire:poll.2s progress strip. --}}

@use('Modules\Core\Public\Support\Lang')
@use('Modules\Core\Public\Support\Fmt')
@use('Modules\Categorization\Public\Enums\RuleCombinator')
<div class="space-y-6">
    <header class="mb-12 space-y-1">
        {{-- Stacked until there is room for both: on a 375pt phone the one-row
             header left the title 141px and squeezed "Re-apply rules to
             history" into a four-line column of single words. --}}
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <x-core::page-heading>{{ Lang::get('categorization::rules.heading') }}</x-core::page-heading>
                <p class="mt-2 max-w-2xl text-sm text-slate-500 dark:text-slate-400">
                    {{ Lang::get('categorization::rules.intro') }}
                </p>
                {{-- Rules never leave the device that authored them, and the
                     backfiller skips them, so the page says so rather than
                     letting a second device look like it is missing them. --}}
                <p class="mt-1 max-w-2xl text-sm text-slate-600 dark:text-slate-400">
                    {{ Lang::get('categorization::rules.device_local_note') }}
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    wire:click="triggerReapply"
                    @disabled($reapplyInFlight)
                    class="pill-btn-ghost"
                >
                    {{ $reapplyInFlight ? Lang::get('categorization::rules.reapplying') : Lang::get('categorization::rules.reapply') }}
                </button>
                <button
                    type="button"
                    wire:click="openCreateModal"
                    class="inline-flex items-center rounded-md bg-emerald-700 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-700 focus-visible:ring-offset-2 dark:bg-emerald-700 dark:hover:bg-emerald-800"
                >
                    {{ Lang::get('categorization::rules.new_rule') }}
                </button>
            </div>
        </div>
    </header>

    @if ($flashMessage !== '')
        <x-core::alert
            tone="positive"
            wire:transition.duration.3000ms
            aria-atomic="true"
            aria-live="polite"
        >
            {{ $flashMessage }}
        </x-core::alert>
    @endif

    @if ($reapplyInFlight && $reapplyProgress !== null)
        {{-- Re-apply progress strip — reuses the EmailScan InboxesPage
             wire:poll.2s.keep-alive idiom verbatim (no new async mechanism). --}}
        <section
            wire:poll.2s.keep-alive="refreshReapplyProgress"
            class="rounded-md border border-slate-200 bg-slate-50 p-4 dark:bg-slate-900 dark:border-slate-700"
            aria-live="polite"
        >
            <p class="text-xs text-slate-700 dark:text-slate-300">
                {{ Lang::get('categorization::rules.reapply_progress_lead') }} <span style="font-variant-numeric: tabular-nums;">{{ Fmt::number((int) ($reapplyProgress['checked'] ?? 0)) }}</span> {{ Lang::get('categorization::rules.reapply_progress_of') }} <span style="font-variant-numeric: tabular-nums;">{{ Fmt::number((int) ($reapplyProgress['total'] ?? 0)) }}</span> {{ Lang::get('categorization::rules.reapply_progress_trail') }}
            </p>
        </section>
    @endif

    @if (count($rules) === 0)
        <x-core::empty-state
            :heading="Lang::get('categorization::rules.empty_heading')"
            :body="Lang::get('categorization::rules.empty_body')"
        >
            <button
                type="button"
                wire:click="openCreateModal"
                class="inline-flex items-center rounded-md bg-emerald-700 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-700 focus-visible:ring-offset-2 dark:bg-emerald-700 dark:hover:bg-emerald-800"
            >
                {{ Lang::get('categorization::rules.empty_cta') }}
            </button>
        </x-core::empty-state>
    @else
        <x-core::data-table class="rules-table">
            <x-slot:head>
                <x-core::th align="left">{{ Lang::get('categorization::rules.col_priority') }}</x-core::th>
                <x-core::th align="left">{{ Lang::get('categorization::rules.col_conditions') }}</x-core::th>
                <x-core::th align="left">{{ Lang::get('categorization::rules.col_actions') }}</x-core::th>
                <x-core::th align="right">{{ Lang::get('categorization::rules.col_hits') }}</x-core::th>
                <x-core::th align="left">{{ Lang::get('categorization::rules.col_created') }}</x-core::th>
                <x-core::th align="right"><span class="sr-only">{{ Lang::get('categorization::rules.col_row_actions') }}</span></x-core::th>
            </x-slot:head>

            @foreach ($rules as $rule)
                <tr class="min-h-12 hover:bg-slate-50 dark:hover:bg-slate-900">
                    {{-- Below 768px the restack drops <thead>, and a bare 10
                         beside a bare 2 names neither column. These two carry
                         their own label there; every other cell says what it
                         is on its own. --}}
                    <td class="px-4 py-3 text-sm text-slate-500 dark:text-slate-400" style="font-variant-numeric: tabular-nums;"><span class="md:hidden">{{ Lang::get('categorization::rules.col_priority') }} </span>{{ $rule->priority }}</td>
                    <td class="px-4 py-3 text-sm text-slate-900 dark:text-slate-100">
                        <div class="flex flex-wrap items-center gap-1">
                            {{-- RuleEngine matches active rules only, and deleting a
                                 category switches every rule that pointed at it off.
                                 Without this the list printed a rule that had not run
                                 since exactly like the live ones above it. --}}
                            @if (! $rule->active)
                                <span class="chip text-amber-700 dark:text-amber-300" title="{{ Lang::get('categorization::rules.inactive_title') }}">{{ Lang::get('categorization::rules.inactive_badge') }}</span>
                            @endif
                            @if (count($rule->conditions) >= 2)
                                <span class="chip">{{ $rule->combinator === RuleCombinator::Any->value ? 'ANY' : 'ALL' }}</span>
                            @endif
                            @if (count($rule->conditions) > 0)
                                <span>{{ \Modules\Categorization\Internal\Http\Livewire\RulesPage::conditionFragment($rule->conditions[0]) }}</span>
                            @endif
                            @if (count($rule->conditions) > 1)
                                @php
                                    $remaining = collect($rule->conditions)->slice(1)->map(fn ($c) => \Modules\Categorization\Internal\Http\Livewire\RulesPage::conditionFragment($c))->implode('; ');
                                @endphp
                                <span class="chip" title="{{ $remaining }}">{{ Lang::get('categorization::rules.more_conditions', ['count' => count($rule->conditions) - 1]) }}</span>
                            @endif
                        </div>
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-900 dark:text-slate-100">
                        <div class="flex flex-wrap items-center gap-1">
                            @foreach ($rule->actions as $action)
                                <span class="chip">{{ \Modules\Categorization\Internal\Http\Livewire\RulesPage::actionChipLabel($action) }}</span>
                            @endforeach
                        </div>
                    </td>
                    <td class="px-4 py-3 text-right text-sm {{ $rule->hitsCount === 0 ? 'text-slate-600 dark:text-slate-400' : 'text-slate-900 dark:text-slate-100' }}" style="font-variant-numeric: tabular-nums;">
                        <span class="md:hidden">{{ Lang::get('categorization::rules.col_hits') }} </span>{{ $rule->hitsCount }}
                    </td>
                    <td class="px-4 py-3 text-xs text-slate-500 dark:text-slate-400" style="font-variant-numeric: tabular-nums;">
                        {{ \Carbon\CarbonImmutable::instance($rule->createdAt)->translatedFormat('d M Y') }}
                    </td>
                    <td class="px-4 py-3 text-right text-sm">
                        @if ($confirmingDeleteId === $rule->id)
                            <x-core::confirm-strip
                                :question="Lang::get('categorization::rules.delete_confirm')"
                                :cancel-label="Lang::get('categorization::rules.cancel')"
                                :confirm-label="Lang::get('categorization::rules.delete_yes')"
                                cancel="cancelDelete"
                                :confirm="'deleteRule('.$rule->id.')'"
                            />
                        @else
                            <div class="flex items-center justify-end gap-2">
                                <button
                                    type="button"
                                    wire:click="openEditModal({{ $rule->id }})"
                                    aria-label="{{ Lang::get('categorization::rules.edit_aria', ['priority' => $rule->priority]) }}"
                                    class="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium text-slate-900 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:text-slate-100 dark:hover:bg-slate-800"
                                >{{ Lang::get('categorization::rules.edit') }}</button>
                                <button
                                    type="button"
                                    wire:click="confirmDelete({{ $rule->id }})"
                                    aria-label="{{ Lang::get('categorization::rules.delete_aria', ['priority' => $rule->priority]) }}"
                                    class="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium text-rose-600 hover:bg-rose-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600 focus-visible:ring-offset-2 dark:text-rose-500 dark:hover:bg-rose-950"
                                >{{ Lang::get('categorization::rules.delete') }}</button>
                            </div>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-core::data-table>

        <p class="mt-6 max-w-3xl text-xs text-slate-500 dark:text-slate-400">
            {{ Lang::get('categorization::rules.footer_note') }}
        </p>
    @endif
</div>
