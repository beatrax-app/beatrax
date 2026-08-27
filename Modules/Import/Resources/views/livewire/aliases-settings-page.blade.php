{{-- Settings → Aliases page.

     Power-user surface over the per-user merchant_aliases table:
     paginated list (25/page) with per-row inline edit of the
     generalized pattern, a live "Test against my transactions"
     preview side pane, checkbox bulk-merge with longest-common-
     prefix prefill, YAML export, and YAML import with a diff preview
     classifying entries as new / unchanged / conflict.

     Layout inherits the sketch 005C settings-section pattern (left
     rail explainer + right rail content). The page sits inside the
     existing Settings shell — the surrounding nav and chrome come
     from layouts.app. --}}

@use('Modules\Core\Public\Support\Lang')
<div class="max-w-5xl mx-auto px-6 py-12 space-y-8" data-testid="aliases-settings-page">

    <header class="space-y-1">
        <x-core::page-heading>{{ Lang::get('import::aliases.heading') }}</x-core::page-heading>
        <p class="max-w-2xl text-sm text-slate-500 dark:text-slate-400">
            {{ Lang::get('import::aliases.subtitle') }}
        </p>
    </header>

    @if ($flashMessage !== '')
        <x-core::alert tone="positive" class="px-4 py-2" wire:transition.duration.3000ms
            aria-atomic="true"
            aria-live="polite">
            {{ $flashMessage }}
            <button type="button" wire:click="clearFlash" class="ml-3 text-xs underline">{{ Lang::get('import::aliases.dismiss') }}</button>
        </x-core::alert>
    @endif

    {{-- Two-column shape: left rail = aliases table; right rail =
         preview pane that fills in when a row is in edit mode.
         The grid collapses to a single column on narrow screens. --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <div class="lg:col-span-2 space-y-4">

            {{-- Bulk-merge header. The button enables only when at
                 least two distinct ids are selected; the LCP service
                 itself rejects size-1 inputs but surfacing the gate
                 here prevents the empty dialog. --}}
            <div class="flex items-center justify-between">
                <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">
                    {{ Lang::get('import::aliases.selected_count', ['count' => count($selectedIds)]) }}
                </p>
                <x-core::neutral-button
                    size="sm"
                    class="disabled:opacity-50 disabled:cursor-not-allowed"
                    :disabled="count($selectedIds) < 2"
                    wire:click="openMergeModal"
                >{{ Lang::get('import::aliases.merge_selected') }}</x-core::neutral-button>
            </div>

            @if (count($aliases) === 0)
                <x-core::empty-state
                    :heading="Lang::get('import::aliases.empty_heading')"
                    :body="Lang::get('import::aliases.empty_body')"
                />
            @else
                <table class="aliases-table">
                    <thead>
                        <tr>
                            <th scope="col" class="w-8"><span class="sr-only">{{ Lang::get('import::aliases.col_select') }}</span></th>
                            <th scope="col">{{ Lang::get('import::aliases.col_raw') }}</th>
                            <th scope="col">{{ Lang::get('import::aliases.col_generalized') }}</th>
                            <th scope="col">{{ Lang::get('import::aliases.col_friendly') }}</th>
                            <th scope="col" class="text-right"><span class="sr-only">{{ Lang::get('import::aliases.col_actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($aliases as $alias)
                            <tr wire:key="alias-row-{{ $alias->id }}">
                                <td>
                                    <input
                                        type="checkbox"
                                        wire:model.live="selectedIds"
                                        value="{{ $alias->id }}"
                                        aria-label="{{ Lang::get('import::aliases.select_alias_aria', ['name' => $alias->friendly_name]) }}"
                                    />
                                </td>
                                <td class="row-pattern">{{ $alias->pattern }}</td>
                                <td>
                                    @if ($editingId === (int) $alias->id)
                                        <input
                                            type="text"
                                            wire:model.live.debounce.400ms="editingPattern"
                                            class="w-full rounded border border-slate-300 bg-white px-2 py-1 text-sm font-mono focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-700 dark:bg-slate-900 dark:border-slate-700"
                                            aria-label="{{ Lang::get('import::aliases.generalized_pattern_aria') }}"
                                            data-testid="editing-pattern-input"
                                        />
                                    @else
                                        <span class="row-pattern">{{ $alias->generalized_pattern }}</span>
                                    @endif
                                </td>
                                <td>{{ $alias->friendly_name }}</td>
                                <td class="text-right">
                                    @if ($editingId === (int) $alias->id)
                                        <button
                                            type="button"
                                            wire:click="saveAlias({{ $alias->id }})"
                                            class="rounded-md bg-emerald-700 px-2 py-1 text-xs font-medium text-white hover:bg-emerald-800"
                                        >{{ Lang::get('import::aliases.save') }}</button>
                                        <button
                                            type="button"
                                            wire:click="cancelEdit"
                                            class="rounded-md px-2 py-1 text-xs font-medium text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800"
                                        >{{ Lang::get('import::aliases.cancel') }}</button>
                                    @else
                                        <button
                                            type="button"
                                            wire:click="startEdit({{ $alias->id }})"
                                            class="rounded-md px-2 py-1 text-xs font-medium text-slate-900 hover:bg-slate-100 dark:text-slate-100 dark:hover:bg-slate-800"
                                        >{{ Lang::get('import::aliases.edit') }}</button>
                                        <button
                                            type="button"
                                            wire:click="deleteAlias({{ $alias->id }})"
                                            wire:confirm="{{ Lang::get('import::aliases.delete_confirm', ['pattern' => $alias->pattern]) }}"
                                            class="rounded-md px-2 py-1 text-xs font-medium text-rose-600 hover:bg-rose-50 dark:text-rose-500 dark:hover:bg-rose-950"
                                        >{{ Lang::get('import::aliases.delete') }}</button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="mt-4">{{ $aliases->links() }}</div>
            @endif

            {{-- YAML export + import footer. --}}
            <div class="border-t border-slate-200 dark:border-slate-700 pt-6 mt-8 space-y-4">
                <h2 class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('import::aliases.backup_transfer') }}</h2>

                <div class="flex flex-wrap items-center gap-3">
                    <x-core::secondary-button
                        size="sm"
                        wire:click="exportYaml"
                    >{{ Lang::get('import::aliases.export_yaml') }}</x-core::secondary-button>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        {!! Lang::get('import::aliases.export_help_html') !!}
                    </p>
                </div>

                <div class="space-y-2">
                    <label for="import-aliases-file" class="block text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('import::aliases.import_from_yaml') }}</label>
                    <x-core::file-input
                        id="import-aliases-file"
                        wire:model="importFile"
                        accept=".yaml,.yml"
                    />
                    @error('importFile')
                        <p class="text-sm text-rose-600 dark:text-rose-500">{{ $message }}</p>
                    @enderror
                    <div class="flex items-center gap-2">
                        <x-core::neutral-button
                            size="sm"
                            class="disabled:opacity-50 disabled:cursor-not-allowed"
                            :disabled="$importFile === null"
                            wire:click="parseUpload"
                        >{{ Lang::get('import::aliases.parse_preview') }}</x-core::neutral-button>
                        @if ($importDiff !== [])
                            <button
                                type="button"
                                wire:click="cancelImport"
                                class="inline-flex items-center rounded-md px-3 py-1.5 text-sm font-medium text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800"
                            >{{ Lang::get('import::aliases.cancel_import') }}</button>
                        @endif
                    </div>
                    @if ($importError !== '')
                        <x-core::alert tone="danger" class="px-4 py-2">
                            {{ $importError }}
                        </x-core::alert>
                    @endif

                    @if ($importDiff !== [])
                        <div class="rounded-md border border-slate-200 bg-slate-50 px-4 py-3 text-sm dark:bg-slate-900 dark:border-slate-700 space-y-3">
                            <p class="text-slate-900 dark:text-slate-100">
                                <strong>{{ count($importDiff['new'] ?? []) }}</strong> {{ Lang::get('import::aliases.diff_new') }}
                                <strong>{{ count($importDiff['unchanged'] ?? []) }}</strong> {{ Lang::get('import::aliases.diff_unchanged') }}
                                <strong>{{ count($importDiff['conflicts'] ?? []) }}</strong> {{ Lang::get('import::aliases.diff_conflicts') }}
                            </p>

                            @if (count($importDiff['conflicts'] ?? []) > 0)
                                <div class="space-y-2">
                                    <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('import::aliases.conflicts_heading') }}</p>
                                    @foreach ($importDiff['conflicts'] as $conflict)
                                        <div class="flex items-start justify-between gap-3" wire:key="conflict-{{ $conflict['entry']['pattern'] }}">
                                            <div class="text-xs space-y-1">
                                                <span class="font-mono text-slate-900 dark:text-slate-100">{{ $conflict['entry']['pattern'] }}</span>
                                                @if ($conflict['existing_name'] !== $conflict['entry']['name'])
                                                    <div class="text-slate-500">{{ Lang::get('import::aliases.conflict_name', ['existing' => $conflict['existing_name'], 'file' => $conflict['entry']['name']]) }}</div>
                                                @endif
                                                @if (($conflict['existing_generalized_pattern'] ?? '') !== $conflict['entry']['generalized_pattern'])
                                                    <div class="text-slate-500">{{ Lang::get('import::aliases.conflict_pattern_existing') }} <span class="font-mono">{{ $conflict['existing_generalized_pattern'] ?? '' }}</span> {{ Lang::get('import::aliases.conflict_file') }} <span class="font-mono">{{ $conflict['entry']['generalized_pattern'] }}</span></div>
                                                @endif
                                            </div>
                                            {{-- $loop->index, not the pattern: a pattern is free text and
                                                 would not survive as an HTML id. --}}
                                            <label for="conflict-resolution-{{ $loop->index }}" class="sr-only">{{ Lang::get('import::aliases.resolution_for_aria', ['pattern' => $conflict['entry']['pattern']]) }}</label>
                                            <select
                                                id="conflict-resolution-{{ $loop->index }}"
                                                wire:model="conflictResolutions.{{ $conflict['entry']['pattern'] }}"
                                                class="rounded border border-slate-300 bg-white px-2 py-1 text-xs dark:bg-slate-900 dark:border-slate-700"
                                            >
                                                <option value="keep">{{ Lang::get('import::aliases.keep_yours') }}</option>
                                                <option value="replace">{{ Lang::get('import::aliases.replace') }}</option>
                                            </select>
                                        </div>
                                    @endforeach
                                </div>
                            @endif

                            <button
                                type="button"
                                wire:click="confirmImport"
                                class="inline-flex items-center rounded-md bg-emerald-700 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-800"
                            >{{ Lang::get('import::aliases.confirm_import') }}</button>
                        </div>
                    @endif
                </div>
            </div>

        </div>

        {{-- Right rail: live preview pane. --}}
        <aside class="space-y-3" aria-label="{{ Lang::get('import::aliases.preview_aria') }}">
            <div class="alias-preview-pane" data-testid="alias-preview-pane">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('import::aliases.test_heading') }}</h2>
                @if ($editingId === 0)
                    <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">
                        {{ Lang::get('import::aliases.test_help') }}
                    </p>
                @elseif ($previewResult === [])
                    <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('import::aliases.typing') }}</p>
                @elseif (($previewResult['emptyMessage'] ?? null) !== null && ($previewResult['total'] ?? 0) === 0)
                    <p class="mt-2 alias-preview-count">{{ $previewResult['emptyMessage'] }}</p>
                @else
                    <p class="mt-2 alias-preview-count">
                        {{ Lang::get('import::aliases.matches_prefix') }} <strong>{{ $previewResult['total'] ?? 0 }}</strong> {{ Lang::get('import::aliases.matches_suffix') }}
                    </p>
                    @if (count($previewResult['first5'] ?? []) > 0)
                        <ul class="mt-3 space-y-1 text-xs">
                            @foreach ($previewResult['first5'] as $row)
                                <li class="flex items-center justify-between gap-2">
                                    <span class="font-mono text-slate-700 dark:text-slate-300 truncate">{{ $row['description'] !== '' ? $row['description'] : $row['counterparty_name'] }}</span>
                                    <span class="text-slate-600 dark:text-slate-400" style="font-variant-numeric: tabular-nums;">{{ $row['booked_at'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                @endif
            </div>
        </aside>
    </div>

    {{-- Bulk-merge confirm dialog. Visible only when openMergeModal()
         set $showMergeModal true. Uses a simple overlay so the layout
         test does not have to handle Flux modal mounting nuance. --}}
    @if ($showMergeModal)
        <div
            role="dialog"
            aria-modal="true"
            aria-labelledby="merge-aliases-title"
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40"
            data-testid="merge-aliases-modal"
        >
            <div class="w-full max-w-md rounded-lg bg-white p-6 shadow-xl dark:bg-slate-900 space-y-4">
                <h2 id="merge-aliases-title" class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ Lang::choice('import::aliases.merge_modal_title', count($selectedIds)) }}</h2>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    {!! Lang::get('import::aliases.merge_modal_help_html') !!}
                </p>
                <x-core::form-field
                    field-id="merge-friendly-name"
                    name="mergeFriendlyName"
                    :label="Lang::get('import::aliases.friendly_name_label')"
                    wire:model="mergeFriendlyName"
                />
                <div class="space-y-1">
                    <x-core::form-field
                        field-id="merge-generalized-pattern"
                        name="mergeGeneralizedPattern"
                        :label="Lang::get('import::aliases.generalized_pattern_label')"
                        wire:model="mergeGeneralizedPattern"
                        class="font-mono"
                    />
                    @if ($mergeGeneralizedPattern === '')
                        <p class="text-xs text-amber-600 dark:text-amber-400">
                            {{ Lang::get('import::aliases.no_prefix_warning') }}
                        </p>
                    @endif
                </div>
                <div class="flex items-center justify-end gap-2 pt-2">
                    <button
                        type="button"
                        wire:click="cancelMerge"
                        class="rounded-md px-3 py-1.5 text-sm font-medium text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800"
                    >{{ Lang::get('import::aliases.cancel') }}</button>
                    <button
                        type="button"
                        wire:click="confirmMerge"
                        @disabled(trim($mergeFriendlyName) === '' || trim($mergeGeneralizedPattern) === '')
                        class="rounded-md bg-emerald-700 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-800 disabled:opacity-50 disabled:cursor-not-allowed"
                    >{{ Lang::get('import::aliases.confirm_merge') }}</button>
                </div>
            </div>
        </div>
    @endif

</div>
