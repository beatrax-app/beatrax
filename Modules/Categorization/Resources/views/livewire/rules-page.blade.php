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
<div class="space-y-6">
    <header class="mb-12 space-y-1">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">{{ Lang::get('categorization::rules.heading') }}</h1>
                <p class="mt-2 max-w-2xl text-sm text-slate-500 dark:text-slate-400">
                    {{ Lang::get('categorization::rules.intro') }}
                </p>
                {{-- Rules never leave the device that authored them, and the
                     backfiller skips them, so the page says so rather than
                     letting a second device look like it is missing them. --}}
                <p class="mt-1 max-w-2xl text-sm text-slate-400 dark:text-slate-500">
                    {{ Lang::get('categorization::rules.device_local_note') }}
                </p>
            </div>
            <div class="flex items-center gap-2">
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
                    class="inline-flex items-center rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2 dark:bg-emerald-500 dark:hover:bg-emerald-400"
                >
                    {{ Lang::get('categorization::rules.new_rule') }}
                </button>
            </div>
        </div>
    </header>

    @if ($flashMessage !== '')
        <div
            wire:transition.duration.3000ms
            aria-atomic="true"
            aria-live="polite"
            class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-700 dark:bg-emerald-950 dark:border-emerald-800 dark:text-emerald-200"
        >
            {{ $flashMessage }}
        </div>
    @endif

    @if ($reapplyInFlight && $reapplyProgress !== null)
        {{-- Re-apply progress strip — reuses the EmailScan InboxesPage
             wire:poll.2s idiom verbatim (no new async mechanism). --}}
        <section
            wire:poll.2s="refreshReapplyProgress"
            class="rounded-md border border-slate-200 bg-slate-50 p-4 dark:bg-slate-900 dark:border-slate-700"
            aria-live="polite"
        >
            <p class="text-xs text-slate-700 dark:text-slate-300">
                {{ Lang::get('categorization::rules.reapply_progress_lead') }} <span style="font-variant-numeric: tabular-nums;">{{ Fmt::number((int) ($reapplyProgress['checked'] ?? 0)) }}</span> {{ Lang::get('categorization::rules.reapply_progress_of') }} <span style="font-variant-numeric: tabular-nums;">{{ Fmt::number((int) ($reapplyProgress['total'] ?? 0)) }}</span> {{ Lang::get('categorization::rules.reapply_progress_trail') }}
            </p>
        </section>
    @endif

    @if (count($rules) === 0)
        <div class="rounded-lg border border-slate-200 bg-slate-50 p-6 text-center dark:bg-slate-900 dark:border-slate-700">
            <h2 class="text-xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">{{ Lang::get('categorization::rules.empty_heading') }}</h2>
            <p class="mx-auto mt-2 max-w-md text-sm text-slate-500 dark:text-slate-400">
                {{ Lang::get('categorization::rules.empty_body') }}
            </p>
            <button
                type="button"
                wire:click="openCreateModal"
                class="mt-8 inline-flex items-center rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2 dark:bg-emerald-500 dark:hover:bg-emerald-400"
            >
                {{ Lang::get('categorization::rules.empty_cta') }}
            </button>
        </div>
    @else
        {{-- overflow-x-auto, not overflow-hidden: this table is the only
                 rendering of these rows at every width, and hidden CLIPPED the
                 right-hand columns on a phone rather than letting them scroll —
                 so the category picker and row actions were unreachable. --}}
            <div class="overflow-x-auto rounded-lg border border-slate-200 dark:border-slate-700">
            <table class="rules-table min-w-full divide-y divide-slate-200 dark:divide-slate-700">
                <thead class="bg-slate-50 dark:bg-slate-900">
                    <tr>
                        <th scope="col" class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('categorization::rules.col_priority') }}</th>
                        <th scope="col" class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('categorization::rules.col_conditions') }}</th>
                        <th scope="col" class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('categorization::rules.col_actions') }}</th>
                        <th scope="col" class="px-4 py-2 text-right text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('categorization::rules.col_hits') }}</th>
                        <th scope="col" class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('categorization::rules.col_created') }}</th>
                        <th scope="col" class="px-4 py-2 text-right text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400"><span class="sr-only">{{ Lang::get('categorization::rules.col_row_actions') }}</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 bg-white dark:bg-slate-950 dark:divide-slate-700">
                    @foreach ($rules as $rule)
                        <tr class="min-h-12 hover:bg-slate-50 dark:hover:bg-slate-900">
                            <td class="px-4 py-3 text-sm text-slate-500 dark:text-slate-400" style="font-variant-numeric: tabular-nums;">{{ $rule->priority }}</td>
                            <td class="px-4 py-3 text-sm text-slate-900 dark:text-slate-100">
                                <div class="flex flex-wrap items-center gap-1">
                                    @if (count($rule->conditions) >= 2)
                                        <span class="chip">{{ $rule->combinator === 'any' ? 'ANY' : 'ALL' }}</span>
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
                            <td class="px-4 py-3 text-right text-sm {{ $rule->hitsCount === 0 ? 'text-slate-400 dark:text-slate-500' : 'text-slate-900 dark:text-slate-100' }}" style="font-variant-numeric: tabular-nums;">
                                {{ $rule->hitsCount }}
                            </td>
                            <td class="px-4 py-3 text-xs text-slate-500 dark:text-slate-400" style="font-variant-numeric: tabular-nums;">
                                {{ \Carbon\CarbonImmutable::instance($rule->createdAt)->translatedFormat('d M Y') }}
                            </td>
                            <td class="px-4 py-3 text-right text-sm">
                                @if ($confirmingDeleteId === $rule->id)
                                    <div class="flex items-center justify-end gap-2">
                                        <span class="text-slate-500 dark:text-slate-400">{{ Lang::get('categorization::rules.delete_confirm') }}</span>
                                        <button
                                            type="button"
                                            wire:click="deleteRule({{ $rule->id }})"
                                            class="rounded-md bg-rose-600 px-2 py-1 text-xs font-medium text-white hover:bg-rose-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600 focus-visible:ring-offset-2 dark:bg-rose-500 dark:hover:bg-rose-400"
                                        >{{ Lang::get('categorization::rules.delete_yes') }}</button>
                                        <button
                                            type="button"
                                            wire:click="cancelDelete"
                                            class="rounded-md px-2 py-1 text-xs font-medium text-slate-500 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:text-slate-400 dark:hover:bg-slate-800"
                                        >{{ Lang::get('categorization::rules.cancel') }}</button>
                                    </div>
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
                </tbody>
            </table>
        </div>

        <p class="mt-6 max-w-3xl text-xs text-slate-500 dark:text-slate-400">
            {{ Lang::get('categorization::rules.footer_note') }}
        </p>
    @endif
</div>
