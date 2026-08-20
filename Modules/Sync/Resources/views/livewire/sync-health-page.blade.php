@use('Modules\Core\Public\Support\Lang')
<div
    class="p-6"
    style="font-family: 'Inter', system-ui, -apple-system, sans-serif;"
    data-testid="sync-health-page"
>
    {{-- Page heading --}}
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('sync::health.title') }}</h1>
    </div>

    {{-- Console pane — 7-day quarantine count (theme-locked dark) --}}
    <div
        class="console-pane mb-6 rounded-lg overflow-hidden border"
        style="background: #0b1220; color: #f1f5f9; border-color: #1e293b;"
        data-testid="sync-health-console-pane"
    >
        <div
            class="p-4"
            style="border-bottom: 1px solid #1e293b;"
        >
            <div
                class="text-xs font-medium uppercase tracking-widest"
                style="color: #94a3b8; letter-spacing: 0.08em; font-size: 11px;"
            >
                {{ Lang::get('sync::health.quarantined_ops') }}
            </div>
            <div
                class="mt-1 text-2xl font-bold tabular-nums"
                style="font-feature-settings: 'tnum'; color: {{ $recentCount > 0 ? '#fca5a5' : '#6ee7b7' }};"
                data-testid="quarantine-count"
            >
                {{ $recentCount }}
                <span
                    class="text-sm font-normal ml-1"
                    style="color: #94a3b8;"
                >
                    {{ Lang::choice('sync::health.skipped', $recentCount) }}
                </span>
            </div>
        </div>
    </div>

    {{-- Recent skips table --}}
    @if ($recentSkips->isEmpty())
        {{-- Calm empty state --}}
        <x-core::alert
            tone="positive"
            class="flex items-center gap-2"
            data-testid="sync-health-empty-state"
        >
            <span aria-hidden="true" class="text-base">✓</span>
            {{ Lang::get('sync::health.empty') }}
        </x-core::alert>
    @else
        {{-- overflow-x-auto, not overflow-hidden: this table is the only
                 rendering of these rows at every width, and hidden CLIPPED the
                 right-hand columns on a phone rather than letting them scroll —
                 so the category picker and row actions were unreachable. --}}
            <div class="overflow-x-auto rounded-lg border border-slate-200 dark:border-slate-700">
            <table
                class="w-full text-sm"
                style="font-size: 13px;"
                data-testid="sync-health-table"
            >
                <thead>
                    <tr class="border-b border-slate-200 bg-slate-50 dark:border-slate-700 dark:bg-slate-900">
                        <th
                            class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400"
                            style="letter-spacing: 0.06em; font-size: 11px;"
                        >
                            {{ Lang::get('sync::health.col_reason') }}
                        </th>
                        <th
                            class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400"
                            style="letter-spacing: 0.06em; font-size: 11px;"
                        >
                            {{ Lang::get('sync::health.col_table') }}
                        </th>
                        <th
                            class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400"
                            style="letter-spacing: 0.06em; font-size: 11px;"
                        >
                            {{ Lang::get('sync::health.col_device') }}
                        </th>
                        <th
                            class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400"
                            style="letter-spacing: 0.06em; font-size: 11px;"
                        >
                            {{ Lang::get('sync::health.col_when') }}
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach ($recentSkips as $skip)
                        @php
                            $vars = get_object_vars($skip);
                            $reason     = is_string($vars['reason'] ?? null)     ? $vars['reason']      : '—';
                            $tableName  = is_string($vars['table_name'] ?? null) ? $vars['table_name']  : '—';
                            $deviceId   = is_string($vars['device_id'] ?? null)  ? $vars['device_id']   : '—';
                            $createdAt  = is_string($vars['created_at'] ?? null) ? $vars['created_at']  : '—';
                        @endphp
                        <tr
                            class="bg-white hover:bg-slate-50 dark:bg-slate-950 dark:hover:bg-slate-900"
                            data-testid="sync-health-row"
                        >
                            <td
                                class="px-4 py-2 font-mono text-rose-700 dark:text-rose-400"
                                style="font-family: 'JetBrains Mono', 'Fira Mono', monospace; font-size: 12px;"
                                data-testid="skip-reason"
                            >
                                {{ $reason }}
                            </td>
                            <td
                                class="px-4 py-2 font-mono text-slate-600 dark:text-slate-300"
                                style="font-family: 'JetBrains Mono', 'Fira Mono', monospace; font-size: 12px;"
                                data-testid="skip-table"
                            >
                                {{ $tableName }}
                            </td>
                            <td
                                class="px-4 py-2 font-mono text-slate-600 dark:text-slate-300"
                                style="font-family: 'JetBrains Mono', 'Fira Mono', monospace; font-size: 12px;"
                                data-testid="skip-device"
                            >
                                {{ $deviceId }}
                            </td>
                            <td
                                class="px-4 py-2 tabular-nums text-slate-500 dark:text-slate-400"
                                style="font-feature-settings: 'tnum'; font-size: 12px;"
                                data-testid="skip-created-at"
                            >
                                {{ $createdAt }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
