<div class="p-8 space-y-6" data-testid="queue-inspector-page">
    <header class="space-y-1">
        <h1 class="text-xl font-semibold text-[var(--color-text)]">Queue inspector</h1>
        <p class="text-sm text-[var(--color-text-muted)]">Pending / failed / batches. Live counts; per-row actions; bulk select + triple-gate on destructive bulk delete.</p>
    </header>

    {{-- Count tiles. wire:poll.5s refreshes the entire component;
         every count derives from the raw query builder so they stay
         in sync with the table below. --}}
    <div class="grid grid-cols-3 gap-3" wire:poll.5s data-testid="queue-count-tiles">
        <div class="card p-3" data-testid="tile-pending">
            <p class="text-[10.5px] uppercase tracking-wide text-[var(--color-text-faint)]">Pending</p>
            <p class="text-3xl font-semibold tabular-nums text-[var(--color-text)]" data-testid="tile-pending-count">{{ $pendingCount }}</p>
        </div>
        <div class="card p-3" data-testid="tile-failed">
            <p class="text-[10.5px] uppercase tracking-wide text-[var(--color-text-faint)]">Failed</p>
            <p class="text-3xl font-semibold tabular-nums {{ $failedCount > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-[var(--color-text)]' }}" data-testid="tile-failed-count">{{ $failedCount }}</p>
        </div>
        <div class="card p-3" data-testid="tile-batches">
            <p class="text-[10.5px] uppercase tracking-wide text-[var(--color-text-faint)]">Batches</p>
            <p class="text-3xl font-semibold tabular-nums text-[var(--color-text)]" data-testid="tile-batches-count">{{ $batchesCount }}</p>
        </div>
    </div>

    {{-- Tab nav row. The three tabs are sub-routes backing the
         same component. Each tab is a real URL so back-button +
         bookmarks behave. --}}
    <nav class="flex items-center gap-1" role="tablist" aria-label="Queue tab">
        @foreach (\Modules\DevMode\Internal\Http\Livewire\QueueInspectorPage::TABS as $tabSlug)
            @php
                $tabLabel = ucfirst($tabSlug);
                $isActive = $tab === $tabSlug;
                $href = route('dev.queue.tab', ['tab' => $tabSlug]);
            @endphp
            <a
                href="{{ $href }}"
                role="tab"
                aria-selected="{{ $isActive ? 'true' : 'false' }}"
                class="inline-flex items-center rounded border px-3 py-1 text-xs font-medium {{ $isActive ? 'border-slate-900 bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900 dark:border-slate-100' : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50 dark:bg-slate-900 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800' }}"
            >{{ $tabLabel }}</a>
        @endforeach
    </nav>

    {{-- Bulk-action bar. Renders only when $selected is non-empty.
         "Retry N" is non-destructive (single-confirm modal);
         "Delete N" dispatches `triple-gate:open`. --}}
    @if (! empty($selected))
        <div class="card flex items-center gap-3 px-3 py-2" data-testid="bulk-actions">
            <span class="text-xs tabular-nums text-[var(--color-text-muted)]" data-testid="bulk-count">{{ count($selected) }} selected</span>
            @if ($tab === 'failed')
                <button
                    type="button"
                    x-data
                    x-on:click="$dispatch('open-modal', 'bulk-retry-confirm')"
                    class="pill-btn inline-flex items-center rounded border border-slate-300 bg-white px-3 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50 dark:bg-slate-900 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                    data-testid="bulk-retry-button"
                >Retry {{ count($selected) }} jobs</button>
            @endif
            <button
                type="button"
                wire:click="bulkDeleteRequest"
                class="pill-btn danger inline-flex items-center rounded border border-rose-300 bg-white px-3 py-1 text-xs font-medium text-rose-700 hover:bg-rose-50 dark:bg-slate-900 dark:border-rose-700 dark:text-rose-300 dark:hover:bg-rose-900/30"
                data-testid="bulk-delete-button"
            >Delete {{ count($selected) }} jobs</button>
        </div>
    @endif

    {{-- Table primitive (UI-SPEC § Dense tables) — 9px 10px cell
         padding, tabular-nums, hover surface-2. Per-tab columns vary
         but the chrome (head, row hover, action column) stays the
         same. --}}
    <div class="card overflow-hidden">
        @if (empty($rows))
            <div class="p-4">
                @if ($tab === 'pending')
                    <p class="text-sm text-[var(--color-text-muted)]">Queue is empty.</p>
                @elseif ($tab === 'failed')
                    <p class="text-sm text-[var(--color-text-muted)]">No failed jobs.</p>
                @else
                    <p class="text-sm text-[var(--color-text-muted)]">No active batches.</p>
                @endif
            </div>
        @else
            <table class="w-full text-sm tabular-nums">
                <thead class="border-b border-slate-200 bg-slate-50 dark:border-slate-700 dark:bg-slate-900">
                    <tr class="text-left text-xs uppercase text-slate-500 dark:text-slate-400">
                        <th class="px-3 py-2 w-8" aria-label="Select"></th>
                        @if ($tab === 'pending')
                            <th class="px-3 py-2">Id</th>
                            <th class="px-3 py-2">Queue</th>
                            <th class="px-3 py-2 text-right">Attempts</th>
                            <th class="px-3 py-2">Created</th>
                        @elseif ($tab === 'failed')
                            <th class="px-3 py-2">UUID</th>
                            <th class="px-3 py-2">Queue</th>
                            <th class="px-3 py-2">Failed</th>
                        @else
                            <th class="px-3 py-2">Batch</th>
                            <th class="px-3 py-2 text-right">Pending</th>
                            <th class="px-3 py-2 text-right">Failed</th>
                            <th class="px-3 py-2">Created</th>
                        @endif
                        <th class="px-3 py-2 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach ($rows as $row)
                        @php
                            $rowKey = $row['key'] ?? '';
                            $isExpanded = $expandedRowId === $rowKey;
                        @endphp
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-900/40" data-testid="queue-row" data-row-key="{{ $rowKey }}">
                            <td class="px-3 py-2 align-top">
                                <input
                                    type="checkbox"
                                    wire:model.live="selected"
                                    value="{{ $rowKey }}"
                                    aria-label="Select row {{ $rowKey }}"
                                />
                            </td>
                            @if ($tab === 'pending')
                                <td class="px-3 py-2 font-mono text-xs">
                                    <button type="button" wire:click="togglePayloadExpand('{{ $rowKey }}')" class="hover:underline">{{ $rowKey }}</button>
                                </td>
                                <td class="px-3 py-2 text-xs text-slate-700 dark:text-slate-300">{{ $row['queue'] ?? '' }}</td>
                                <td class="px-3 py-2 text-right text-xs text-slate-700 dark:text-slate-300">{{ $row['attempts'] ?? 0 }}</td>
                                <td class="px-3 py-2 text-xs text-slate-500 dark:text-slate-400">
                                    @if (! empty($row['createdAt']))
                                        {{ \Carbon\CarbonImmutable::createFromTimestamp($row['createdAt'])->diffForHumans() }}
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-right">
                                    <button
                                        type="button"
                                        wire:click="delete({{ (int) $rowKey }})"
                                        wire:confirm="Delete pending job #{{ $rowKey }}?"
                                        class="text-xs font-medium text-rose-700 hover:underline dark:text-rose-400"
                                        data-testid="row-delete-button"
                                    >Delete job</button>
                                </td>
                            @elseif ($tab === 'failed')
                                <td class="px-3 py-2 font-mono text-xs">
                                    <button type="button" wire:click="togglePayloadExpand('{{ $rowKey }}')" class="hover:underline">{{ $row['uuid'] ?? '' }}</button>
                                </td>
                                <td class="px-3 py-2 text-xs text-slate-700 dark:text-slate-300">{{ $row['queue'] ?? '' }}</td>
                                <td class="px-3 py-2 text-xs text-slate-500 dark:text-slate-400">
                                    @if (! empty($row['failedAt']))
                                        {{ $row['failedAt']->diffForHumans() }}
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-right space-x-2">
                                    <button
                                        type="button"
                                        wire:click="retry('{{ $row['uuid'] ?? '' }}')"
                                        class="text-xs font-medium text-slate-700 hover:underline dark:text-slate-200"
                                        data-testid="row-retry-button"
                                    >Retry job</button>
                                    <button
                                        type="button"
                                        wire:click="forget('{{ $row['uuid'] ?? '' }}')"
                                        wire:confirm="Remove failed job {{ $row['uuid'] ?? '' }}?"
                                        class="text-xs font-medium text-rose-700 hover:underline dark:text-rose-400"
                                        data-testid="row-forget-button"
                                    >Delete job</button>
                                </td>
                            @else
                                <td class="px-3 py-2 font-mono text-xs">
                                    <button type="button" wire:click="togglePayloadExpand('{{ $rowKey }}')" class="hover:underline">{{ $row['name'] ?? '' }}</button>
                                </td>
                                <td class="px-3 py-2 text-right text-xs">{{ $row['pendingJobs'] ?? 0 }}</td>
                                <td class="px-3 py-2 text-right text-xs {{ ($row['failedJobs'] ?? 0) > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-slate-700 dark:text-slate-300' }}">{{ $row['failedJobs'] ?? 0 }}</td>
                                <td class="px-3 py-2 text-xs text-slate-500 dark:text-slate-400">
                                    @if (! empty($row['createdAt']))
                                        {{ \Carbon\CarbonImmutable::createFromTimestamp($row['createdAt'])->diffForHumans() }}
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-right space-x-2">
                                    @if (($row['failedJobs'] ?? 0) > 0)
                                        <button
                                            type="button"
                                            wire:click="retryFailures('{{ $rowKey }}')"
                                            class="text-xs font-medium text-slate-700 hover:underline dark:text-slate-200"
                                            data-testid="row-retry-failures-button"
                                        >Retry failures</button>
                                    @endif
                                    @if (empty($row['cancelledAt']) && empty($row['finishedAt']))
                                        <button
                                            type="button"
                                            wire:click="cancel('{{ $rowKey }}')"
                                            wire:confirm="Cancel batch {{ $row['name'] ?? '' }}?"
                                            class="text-xs font-medium text-slate-700 hover:underline dark:text-slate-200"
                                            data-testid="row-cancel-button"
                                        >Cancel batch</button>
                                    @endif
                                    <button
                                        type="button"
                                        wire:click="deleteBatch('{{ $rowKey }}')"
                                        wire:confirm="Delete batch {{ $row['name'] ?? '' }}?"
                                        class="text-xs font-medium text-rose-700 hover:underline dark:text-rose-400"
                                        data-testid="row-delete-batch-button"
                                    >Delete batch</button>
                                </td>
                            @endif
                        </tr>
                        @if ($isExpanded && $expandedPayload !== null)
                            <tr class="bg-slate-50 dark:bg-slate-900/60" data-testid="payload-viewer-row">
                                <td colspan="6" class="px-3 py-2">
                                    <pre class="overflow-auto rounded bg-slate-950 px-3 py-2 text-[11px] font-mono text-slate-200 max-h-72"><code>{{ $expandedPayload }}</code></pre>
                                </td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    {{-- Bulk-retry single-confirm modal. Non-destructive — no
         triple-gate. Flux's open-modal dispatch triggers the modal;
         confirming wires through to bulkRetryConfirm(). --}}
    <flux:modal name="bulk-retry-confirm" :dismissible="true">
        <div class="space-y-4">
            <flux:heading size="lg">Retry {{ count($selected) }} jobs?</flux:heading>
            <p class="text-sm text-slate-700 dark:text-slate-300">
                The selected failed jobs will be re-queued for the worker to retry.
            </p>
            <div class="flex items-center justify-end gap-2">
                <button
                    type="button"
                    x-data
                    x-on:click="$dispatch('close-modal', 'bulk-retry-confirm')"
                    class="inline-flex items-center rounded-md px-4 py-2 text-sm font-medium text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800"
                >Cancel</button>
                <button
                    type="button"
                    wire:click="bulkRetryConfirm"
                    x-data
                    x-on:click="$dispatch('close-modal', 'bulk-retry-confirm')"
                    class="pill-btn primary inline-flex items-center rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-300"
                >Retry {{ count($selected) }} jobs</button>
            </div>
        </div>
    </flux:modal>
</div>
