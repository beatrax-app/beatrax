<div class="flex min-h-full" data-testid="sql-panel-page">
    {{-- Inner sidebar: Schema viewer (lives inside /dev/sql, not a separate route). --}}
    <aside
        class="border-r border-[var(--color-border)] w-[220px] flex-shrink-0 overflow-auto"
        data-testid="schema-viewer"
        aria-label="Schema viewer"
    >
        <div class="p-3 border-b border-[var(--color-border)]">
            <h2 class="text-xs uppercase tracking-wide text-[var(--color-text-muted)]">Tables</h2>
        </div>
        <ul class="text-sm" x-data="{ openTable: null }">
            @foreach ($tables as $table)
                <li class="border-b border-[var(--color-border)]">
                    <div class="flex items-center justify-between p-2">
                        <button
                            type="button"
                            class="font-mono text-xs hover:underline text-left"
                            x-on:click="openTable = openTable === '{{ $table['name'] }}' ? null : '{{ $table['name'] }}'"
                        >
                            {{ $table['name'] }}
                        </button>
                        <span class="text-[10px] text-[var(--color-text-muted)]" style="font-variant-numeric: tabular-nums;">
                            {{ $table['row_count'] ?? '—' }}
                        </span>
                    </div>
                    <div
                        x-show="openTable === '{{ $table['name'] }}'"
                        x-cloak
                        class="px-2 pb-2 space-y-1 text-[11px]"
                    >
                        <div>
                            <span class="text-[var(--color-text-muted)] uppercase tracking-wide">columns</span>
                            <ul class="font-mono">
                                @foreach ($table['columns'] as $col)
                                    @php
                                        $name = is_array($col) && isset($col['name']) ? $col['name'] : '?';
                                        $type = is_array($col) && isset($col['type_name']) ? $col['type_name'] : (is_array($col) && isset($col['type']) ? $col['type'] : '');
                                        $nullable = is_array($col) ? (($col['nullable'] ?? false) === true ? 'NULL' : 'NOT NULL') : '';
                                    @endphp
                                    <li>{{ $name }} <span class="text-[var(--color-text-muted)]">{{ $type }} · {{ $nullable }}</span></li>
                                @endforeach
                            </ul>
                        </div>
                        @if (count($table['indexes']) > 0)
                            <div>
                                <span class="text-[var(--color-text-muted)] uppercase tracking-wide">indexes</span>
                                <ul class="font-mono">
                                    @foreach ($table['indexes'] as $idx)
                                        <li>{{ is_array($idx) && isset($idx['name']) ? $idx['name'] : '?' }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                        @if (count($table['foreign_keys']) > 0)
                            <div>
                                <span class="text-[var(--color-text-muted)] uppercase tracking-wide">foreign keys</span>
                                <ul class="font-mono">
                                    @foreach ($table['foreign_keys'] as $fk)
                                        <li>{{ is_array($fk) && isset($fk['name']) ? $fk['name'] : '?' }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                        <button
                            type="button"
                            class="pill-btn text-xs"
                            wire:click="browseTable('{{ $table['name'] }}')"
                            data-testid="schema-browse-{{ $table['name'] }}"
                        >Browse</button>
                    </div>
                </li>
            @endforeach
        </ul>
    </aside>

    {{-- Main pane: SQL input + results --}}
    <div class="flex-1 p-6 space-y-4 overflow-auto">
        <header class="space-y-1">
            <h1 class="text-xl font-semibold text-[var(--color-text)]">SQL</h1>
            <p class="text-sm text-[var(--color-text-muted)]">
                SELECT-only query panel. The validator (parse-time) and PRAGMA
                <code class="font-mono text-xs">query_only = 1</code>
                (engine-time) reject every non-SELECT. Hard 5-second wall-clock cap.
            </p>
        </header>

        @if (! $advancedOn)
            <div
                class="rounded border border-amber-300 bg-amber-50 dark:bg-amber-900/10 text-amber-700 dark:text-amber-300 p-3 text-sm"
                data-testid="sql-advanced-banner"
            >
                <strong>Advanced mode is OFF.</strong>
                <span>Enable Advanced (Dev Mode → Advanced) to run queries.</span>
            </div>
        @endif

        <div class="{{ $advancedOn ? '' : 'opacity-50 pointer-events-none' }}">
            <label for="sql-textarea" class="block text-xs uppercase tracking-wide text-[var(--color-text-muted)] mb-1">
                SELECT statement
            </label>
            <textarea
                id="sql-textarea"
                wire:model.lazy="sqlInput"
                rows="6"
                class="w-full font-mono text-sm border border-[var(--color-border)] rounded p-3 bg-white dark:bg-slate-900"
                placeholder="SELECT * FROM users LIMIT 10"
                data-testid="sql-textarea"
            ></textarea>

            <div class="mt-3 flex items-center justify-between">
                <button
                    type="button"
                    class="pill-btn primary"
                    wire:click="run"
                    data-testid="sql-run-button"
                >Run</button>
                @if ($rowcount !== null)
                    <span class="text-xs text-[var(--color-text-muted)]" style="font-variant-numeric: tabular-nums;">
                        {{ $rowcount }} rows · {{ $durationMs }}ms
                    </span>
                @endif
            </div>
        </div>

        @if ($errorMessage !== '')
            <div
                class="rounded border border-rose-300 bg-rose-50 dark:bg-rose-900/10 text-rose-700 dark:text-rose-300 p-3 text-sm"
                data-testid="sql-error"
            >
                {{ $errorMessage }}
            </div>
        @elseif ($rowcount !== null && $rowcount === 0)
            <div
                class="text-sm text-[var(--color-text-muted)]"
                data-testid="sql-empty-result"
            >Query returned no rows.</div>
        @elseif ($rowcount !== null && count($resultRows) > 0)
            <table class="table text-sm w-full" style="font-variant-numeric: tabular-nums;" data-testid="sql-result-table">
                <thead>
                    <tr>
                        @foreach ($resultColumns as $col)
                            <th class="text-left text-[11px] uppercase text-[var(--color-text-muted)]">{{ $col }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($resultRows as $row)
                        <tr>
                            @foreach ($resultColumns as $col)
                                @php
                                    $value = $row[$col] ?? null;
                                @endphp
                                <td class="font-mono text-xs {{ is_numeric($value) ? 'text-right' : 'text-left' }}">
                                    {{ is_null($value) ? 'NULL' : (is_scalar($value) ? $value : json_encode($value)) }}
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
