<div class="p-8 space-y-6" data-testid="audit-log-page">
    <header class="space-y-1">
        <h1 class="text-xl font-semibold text-[var(--color-text)]">Audit log</h1>
        <p class="text-sm text-[var(--color-text-muted)]">Every command, queue action, and SQL query run through the Dev Console.</p>
    </header>

    {{-- Filter chips row --}}
    <div class="flex flex-wrap items-center gap-3">
        <div class="flex items-center gap-2">
            <label for="audit-filter-tier" class="text-xs text-slate-500 dark:text-slate-400">Tier</label>
            <select
                id="audit-filter-tier"
                wire:model.live="tierFilter"
                class="rounded border border-slate-200 bg-white px-2 py-1 text-xs text-slate-900 dark:bg-slate-900 dark:border-slate-700 dark:text-slate-100"
            >
                <option value="">All</option>
                <option value="safe">SAFE</option>
                <option value="destructive">DESTRUCTIVE</option>
            </select>
        </div>
        <div class="flex items-center gap-2">
            <label for="audit-filter-caller" class="text-xs text-slate-500 dark:text-slate-400">Caller</label>
            <input
                id="audit-filter-caller"
                type="text"
                wire:model.live.debounce.400ms="callerFilter"
                placeholder="username"
                class="w-40 rounded border border-slate-200 bg-white px-2 py-1 text-xs text-slate-900 dark:bg-slate-900 dark:border-slate-700 dark:text-slate-100"
            />
        </div>
        <div class="flex items-center gap-2">
            <label for="audit-filter-command" class="text-xs text-slate-500 dark:text-slate-400">Command</label>
            <input
                id="audit-filter-command"
                type="text"
                wire:model.live.debounce.400ms="commandFilter"
                placeholder="db:restore"
                class="w-48 rounded border border-slate-200 bg-white px-2 py-1 text-xs text-slate-900 dark:bg-slate-900 dark:border-slate-700 dark:text-slate-100"
            />
        </div>
        <button
            type="button"
            wire:click="clearFilters"
            class="text-xs text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-100"
        >Clear</button>
    </div>

    <div class="card overflow-hidden">
        @if ($rows->isEmpty())
            <div class="p-4">
                <p class="text-sm text-[var(--color-text-muted)]">No audit rows match the current filters.</p>
            </div>
        @else
            <table class="w-full text-sm tabular-nums">
                <thead class="border-b border-slate-200 bg-slate-50 dark:border-slate-700 dark:bg-slate-900">
                    <tr class="text-left text-xs uppercase text-slate-500 dark:text-slate-400">
                        <th class="px-3 py-2">Command</th>
                        <th class="px-3 py-2">Tier</th>
                        <th class="px-3 py-2">Caller</th>
                        <th class="px-3 py-2">Started</th>
                        <th class="px-3 py-2">Exit</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach ($rows as $row)
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
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
