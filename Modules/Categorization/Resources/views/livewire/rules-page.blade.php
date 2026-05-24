{{-- /rules page.

     Renders the user's categorization-rule list as a calm table with
     field / match / value / category / hits / created columns. The
     `New rule` button + per-row Edit chip dispatch the global
     `rule-form:open` Livewire event so the globally-mounted
     RuleFormModal SFC handles the form. The per-row Delete chip
     collapses into a two-step inline confirmation. --}}

<div class="space-y-6">
    <header class="mb-12 space-y-1">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">Rules</h1>
                <p class="mt-2 max-w-2xl text-sm text-slate-500 dark:text-slate-400">
                    Pre-categorize transactions on import. Rules apply to every source — bank, card, PayPal, and email receipts.
                </p>
            </div>
            <button
                type="button"
                wire:click="openCreateModal"
                class="inline-flex items-center rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2 dark:bg-emerald-500 dark:hover:bg-emerald-400"
            >
                New rule
            </button>
        </div>
    </header>

    @if ($flashMessage !== '')
        <div
            wire:transition.duration.3000ms
            role="status"
            aria-live="polite"
            class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-700 dark:bg-emerald-950 dark:border-emerald-800 dark:text-emerald-200"
        >
            {{ $flashMessage }}
        </div>
    @endif

    @if (count($rules) === 0)
        <div class="rounded-lg border border-slate-200 bg-slate-50 p-6 text-center dark:bg-slate-900 dark:border-slate-700">
            <h2 class="text-xl font-semibold tracking-tight text-slate-900 dark:text-slate-100">No rules yet</h2>
            <p class="mx-auto mt-2 max-w-md text-sm text-slate-500 dark:text-slate-400">
                Rules pre-categorize transactions on import. Each rule matches a field of a transaction and assigns a category automatically.
            </p>
            <button
                type="button"
                wire:click="openCreateModal"
                class="mt-8 inline-flex items-center rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2 dark:bg-emerald-500 dark:hover:bg-emerald-400"
            >
                Create your first rule
            </button>
        </div>
    @else
        <div class="overflow-hidden rounded-lg border border-slate-200 dark:border-slate-700">
            <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-700">
                <thead class="bg-slate-50 dark:bg-slate-900">
                    <tr>
                        <th scope="col" class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Field</th>
                        <th scope="col" class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Match</th>
                        <th scope="col" class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Value</th>
                        <th scope="col" class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Category</th>
                        <th scope="col" class="px-4 py-2 text-right text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Hits</th>
                        <th scope="col" class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Created</th>
                        <th scope="col" class="px-4 py-2 text-right text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 bg-white dark:bg-slate-950 dark:divide-slate-700">
                    @foreach ($rules as $rule)
                        <tr class="min-h-12 hover:bg-slate-50 dark:hover:bg-slate-900">
                            <td class="px-4 py-3 text-sm text-slate-900 dark:text-slate-100">{{ $rule->field }}</td>
                            <td class="px-4 py-3 text-sm text-slate-500 dark:text-slate-400">{{ $rule->match }}</td>
                            <td class="px-4 py-3 text-sm font-medium text-slate-900 font-mono dark:text-slate-100">{{ $rule->value }}</td>
                            <td class="px-4 py-3 text-sm text-slate-900 dark:text-slate-100">{{ $rule->categoryPath }}</td>
                            <td class="px-4 py-3 text-right text-sm {{ $rule->hitsCount === 0 ? 'text-slate-400 dark:text-slate-500' : 'text-slate-900 dark:text-slate-100' }}" style="font-variant-numeric: tabular-nums;">
                                {{ $rule->hitsCount }}
                            </td>
                            <td class="px-4 py-3 text-xs text-slate-500 dark:text-slate-400" style="font-variant-numeric: tabular-nums;">
                                {{ $rule->createdAt->format('d M Y') }}
                            </td>
                            <td class="px-4 py-3 text-right text-sm">
                                @if ($confirmingDeleteId === $rule->id)
                                    <div class="flex items-center justify-end gap-2">
                                        <span class="text-slate-500 dark:text-slate-400">Delete?</span>
                                        <button
                                            type="button"
                                            wire:click="deleteRule({{ $rule->id }})"
                                            class="rounded-md bg-rose-600 px-2 py-1 text-xs font-medium text-white hover:bg-rose-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600 focus-visible:ring-offset-2 dark:bg-rose-500 dark:hover:bg-rose-400"
                                        >Yes, delete</button>
                                        <button
                                            type="button"
                                            wire:click="cancelDelete"
                                            class="rounded-md px-2 py-1 text-xs font-medium text-slate-500 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:text-slate-400 dark:hover:bg-slate-800"
                                        >Cancel</button>
                                    </div>
                                @else
                                    <div class="flex items-center justify-end gap-2">
                                        <button
                                            type="button"
                                            wire:click="openEditModal({{ $rule->id }})"
                                            aria-label="Edit rule for {{ $rule->field }} {{ $rule->match }} '{{ $rule->value }}'"
                                            class="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium text-slate-900 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:text-slate-100 dark:hover:bg-slate-800"
                                        >Edit</button>
                                        <button
                                            type="button"
                                            wire:click="confirmDelete({{ $rule->id }})"
                                            aria-label="Delete rule for {{ $rule->field }} {{ $rule->match }} '{{ $rule->value }}'"
                                            class="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium text-rose-600 hover:bg-rose-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600 focus-visible:ring-offset-2 dark:text-rose-500 dark:hover:bg-rose-950"
                                        >Delete</button>
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <p class="mt-6 max-w-3xl text-xs text-slate-500 dark:text-slate-400">
            Rules and merchant history work together. Deleting a rule doesn't clear what beatrax has learned from past categorizations — the next import may still auto-suggest the same category from history.
        </p>
    @endif
</div>
