@use('Modules\Core\Public\Support\Lang')
{{-- D-20 / UI-SPEC §19: overflow-x-auto wrapper ensures the dense audit log
     table scrolls horizontally at phone width without breaking the page layout. --}}
<div class="p-8 space-y-6 overflow-x-auto" data-testid="audit-log-page">
    <header class="flex items-start justify-between gap-4">
        <div class="space-y-1">
            <h1 class="text-xl font-semibold text-[var(--color-text)]">{{ Lang::get('dev::audit.heading') }}</h1>
            <p class="text-sm text-[var(--color-text-muted)]">{{ Lang::get('dev::audit.subtitle') }}</p>
        </div>
        {{--
            Page-level "Clear all" action.

            The confirm dialog is rendered via Alpine `window.confirm()`
            rather than a Livewire-side TripleGateModal because the
            audit log is the operational write-only log of past
            actions — wiping it deletes history but cannot corrupt
            live data, run a destructive command, or affect another
            user's working state. A single confirm gate is the right
            speed bump.

            `data-testid="audit-truncate-button"` is the hook the
            AuditLogTruncatePageTest uses to assert the button
            renders.
        --}}
        <button
            type="button"
            x-data
            x-on:click="if (window.confirm(@js(Lang::get('dev::audit.clear_all_confirm')))) { $wire.truncateAll() }"
            class="inline-flex items-center rounded-md border border-rose-200 bg-white px-3 py-1.5 text-xs font-medium text-rose-700 hover:bg-rose-50 focus-visible:ring-2 focus-visible:ring-rose-600 focus-visible:ring-offset-2 dark:bg-slate-950 dark:border-rose-900 dark:text-rose-400 dark:hover:bg-rose-950"
            data-testid="audit-truncate-button"
        >{{ Lang::get('dev::audit.clear_all') }}</button>
    </header>

    {{-- Filter chips row --}}
    <div class="flex flex-wrap items-center gap-3">
        <div class="flex items-center gap-2">
            <label for="audit-filter-tier" class="text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('dev::audit.filter_tier') }}</label>
            <select
                id="audit-filter-tier"
                wire:model.live="tierFilter"
                class="rounded border border-slate-200 bg-white px-2 py-1 text-xs text-slate-900 dark:bg-slate-900 dark:border-slate-700 dark:text-slate-100"
            >
                <option value="">{{ Lang::get('dev::audit.filter_all') }}</option>
                <option value="safe">{{ Lang::get('dev::common.tier.safe') }}</option>
                <option value="destructive">{{ Lang::get('dev::common.tier.destructive') }}</option>
            </select>
        </div>
        <div class="flex items-center gap-2">
            <label for="audit-filter-caller" class="text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('dev::audit.filter_caller') }}</label>
            <input
                id="audit-filter-caller"
                type="text"
                wire:model.live.debounce.400ms="callerFilter"
                placeholder="{{ Lang::get('dev::audit.caller_placeholder') }}"
                class="w-40 rounded border border-slate-200 bg-white px-2 py-1 text-xs text-slate-900 dark:bg-slate-900 dark:border-slate-700 dark:text-slate-100"
            />
        </div>
        <div class="flex items-center gap-2">
            <label for="audit-filter-command" class="text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('dev::audit.filter_command') }}</label>
            <input
                id="audit-filter-command"
                type="text"
                wire:model.live.debounce.400ms="commandFilter"
                placeholder="{{ Lang::get('dev::audit.command_placeholder') }}"
                class="w-48 rounded border border-slate-200 bg-white px-2 py-1 text-xs text-slate-900 dark:bg-slate-900 dark:border-slate-700 dark:text-slate-100"
            />
        </div>
        <button
            type="button"
            wire:click="clearFilters"
            class="text-xs text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-100"
        >{{ Lang::get('dev::audit.clear') }}</button>
    </div>

    <div class="card overflow-hidden">
        @if ($rows->isEmpty())
            <div class="p-4">
                <p class="text-sm text-[var(--color-text-muted)]">{{ Lang::get('dev::audit.empty') }}</p>
            </div>
        @else
            <table class="w-full text-sm tabular-nums">
                <thead class="border-b border-slate-200 bg-slate-50 dark:border-slate-700 dark:bg-slate-900">
                    <tr class="text-left text-xs uppercase text-slate-500 dark:text-slate-400">
                        <th class="px-3 py-2">{{ Lang::get('dev::audit.col_command') }}</th>
                        <th class="px-3 py-2">{{ Lang::get('dev::audit.col_tier') }}</th>
                        <th class="px-3 py-2">{{ Lang::get('dev::audit.col_caller') }}</th>
                        <th class="px-3 py-2">{{ Lang::get('dev::audit.col_started') }}</th>
                        <th class="px-3 py-2">{{ Lang::get('dev::audit.col_exit') }}</th>
                        <th class="px-3 py-2"><span class="sr-only">{{ Lang::get('dev::audit.col_copy') }}</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach ($rows as $row)
                        @php
                            /*
                             * Assemble the verbatim "full output" payload the
                             * per-row Copy button writes to the clipboard.
                             * One human-readable text blob carrying every
                             * captured field on the row so the developer can
                             * paste it into a chat / issue / scratchpad
                             * without round-tripping through the DB.
                             *
                             * The stored excerpts are already redacted +
                             * length-capped by RedactionExcerptCap at write
                             * time, so this surface re-emits the same safe
                             * payload — no fresh redaction needed here.
                             */
                            $copyParts = [
                                'command: '.$row['command'],
                                'tier: '.$row['tier'],
                                'exit_code: '.($row['exitCode'] ?? '—'),
                                'caller: '.($row['username'] !== '' ? $row['username'] : '—'),
                                'started_at: '.($row['createdAt'] ?? '—'),
                            ];
                            if ($row['args'] !== []) {
                                $copyParts[] = 'args: '.json_encode($row['args'], JSON_UNESCAPED_SLASHES);
                            }
                            if ($row['stdout'] !== '') {
                                $copyParts[] = "---- stdout ----\n".$row['stdout'];
                            }
                            if ($row['error'] !== '') {
                                $copyParts[] = "---- stderr ----\n".$row['error'];
                            }
                            $copyPayload = implode("\n", $copyParts);
                        @endphp
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-900/40">
                            <td class="px-3 py-2 font-mono text-xs text-slate-900 dark:text-slate-100">
                                {{ $row['command'] }}
                            </td>
                            <td class="px-3 py-2"><x-dev::tier-chip :tier="$row['tier']" /></td>
                            <td class="px-3 py-2 text-xs text-slate-700 dark:text-slate-300">{{ $row['username'] }}</td>
                            <td class="px-3 py-2 text-xs text-slate-500 dark:text-slate-400">
                                @if ($row['createdAt'])
                                    {{ \Carbon\CarbonImmutable::parse($row['createdAt'])->diffForHumans() }}
                                @endif
                            </td>
                            <td class="px-3 py-2 text-xs @if ($row['exitCode'] !== 0 && $row['exitCode'] !== null) text-rose-600 dark:text-rose-400 @else text-slate-700 dark:text-slate-300 @endif">
                                {{ $row['exitCode'] ?? '—' }}
                            </td>
                            <td class="px-3 py-2 text-right">
                                {{--
                                    Per-row Copy affordance. The payload is
                                    serialised to JSON via @js so embedded
                                    newlines + quotes round-trip into the
                                    JS string literal cleanly. clipboard.
                                    writeText is the modern API; the catch
                                    swallows the SecurityError that fires
                                    in browsers blocking clipboard access
                                    over insecure origins so the button
                                    never raises an unhandled rejection.
                                --}}
                                <button
                                    type="button"
                                    x-data="{ copied: false }"
                                    x-on:click="
                                        try {
                                            await window.beatraxCopy(@js($copyPayload));
                                            copied = true;
                                            setTimeout(() => copied = false, 1500);
                                        } catch (e) {}
                                    "
                                    class="inline-flex items-center rounded border border-slate-200 bg-white px-2 py-0.5 text-[10px] font-medium uppercase tracking-wide text-slate-600 hover:bg-slate-50 dark:bg-slate-900 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
                                    data-testid="audit-row-copy-button"
                                    aria-label="{{ Lang::get('dev::audit.copy_row_aria', ['id' => $row['id']]) }}"
                                >
                                    <span x-show="!copied">{{ Lang::get('dev::audit.copy') }}</span>
                                    <span x-show="copied" x-cloak>{{ Lang::get('dev::audit.copied') }}</span>
                                </button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    {{-- Cursor pager: walks back through history by pinning the
         smallest rendered id as ?before=<id>. --}}
    @if ($hasMore || $isPaged)
        <nav class="flex items-center justify-between text-xs text-slate-500 dark:text-slate-400" aria-label="{{ Lang::get('dev::audit.pagination_aria') }}">
            <button
                type="button"
                wire:click="newer"
                @disabled(! $isPaged)
                class="rounded border px-3 py-1 font-medium {{ $isPaged ? 'border-slate-200 bg-white hover:bg-slate-50 dark:bg-slate-900 dark:border-slate-700 dark:hover:bg-slate-800' : 'cursor-not-allowed border-slate-100 bg-slate-50 text-slate-400 dark:bg-slate-950 dark:border-slate-800 dark:text-slate-600' }}"
            >{{ Lang::get('dev::audit.newer') }}</button>
            <button
                type="button"
                wire:click="older({{ $oldestRenderedId }})"
                @disabled(! $hasMore || $oldestRenderedId <= 0)
                class="rounded border px-3 py-1 font-medium {{ $hasMore && $oldestRenderedId > 0 ? 'border-slate-200 bg-white hover:bg-slate-50 dark:bg-slate-900 dark:border-slate-700 dark:hover:bg-slate-800' : 'cursor-not-allowed border-slate-100 bg-slate-50 text-slate-400 dark:bg-slate-950 dark:border-slate-800 dark:text-slate-600' }}"
            >{{ Lang::get('dev::audit.older') }}</button>
        </nav>
    @endif
</div>
