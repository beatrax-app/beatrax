@use('Modules\Core\Public\Support\Lang')
@use('Modules\Core\Public\Support\Fmt')
<div
    class="p-6"
    style="font-family: system-ui, -apple-system, sans-serif;"
    data-testid="sync-health-page"
>
    {{-- Page heading --}}
    <div class="mb-6 flex flex-wrap items-center justify-between">
        <h1 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('sync::health.title') }}</h1>
    </div>

    {{-- Console pane — 7-day quarantine count (theme-locked dark) --}}
    <div
        class="console-pane mb-6 rounded-lg overflow-hidden border"
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
                {{ Lang::choice('sync::health.skipped', $recentCount) }}
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
        {{-- The header counts every skip in the window, the table carries the
             50 most recent, and without this line the gap between them is
             invisible: 616 above a list of 50 read as the whole of it, and the
             rows it does not draw are not the rows it does — the visible 50
             were one table where the rest held another. --}}
        @if ($recentCount > $recentSkips->count())
            <p class="mb-2 text-xs text-slate-500 dark:text-slate-400" data-testid="sync-health-truncated">
                {{ Lang::get('core::components.showing_recent', ['shown' => Fmt::number($recentSkips->count()), 'count' => Fmt::number($recentCount)]) }}
            </p>
        @endif

        {{-- overflow-x-auto, not overflow-hidden: four diagnostic columns do
             not fit a phone, and this table is their only rendering at any
             width, so clipping would take the timestamp off the screen for
             good. --}}
            <div class="overflow-x-auto rounded-lg border border-slate-200 dark:border-slate-700">
            <table
                class="w-full text-sm"
                style="font-size: 13px;"
                data-testid="sync-health-table"
            >
                <thead>
                    <tr class="border-b border-slate-200 bg-slate-50 dark:border-slate-700 dark:bg-slate-900">
                        <x-core::th
                            align="left"
                            style="letter-spacing: 0.06em; font-size: 11px;"
                        >
                            {{ Lang::get('sync::health.col_reason') }}
                        </x-core::th>
                        <x-core::th
                            align="left"
                            style="letter-spacing: 0.06em; font-size: 11px;"
                        >
                            {{ Lang::get('sync::health.col_table') }}
                        </x-core::th>
                        <x-core::th
                            align="left"
                            style="letter-spacing: 0.06em; font-size: 11px;"
                        >
                            {{ Lang::get('sync::health.col_device') }}
                        </x-core::th>
                        <x-core::th
                            align="left"
                            style="letter-spacing: 0.06em; font-size: 11px;"
                        >
                            {{ Lang::get('sync::health.col_when') }}
                        </x-core::th>
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
                                style="font-family: ui-monospace, 'SF Mono', monospace; font-size: 12px;"
                                data-testid="skip-reason"
                            >
                                {{ $reason }}
                            </td>
                            <td
                                class="px-4 py-2 font-mono text-slate-600 dark:text-slate-300"
                                style="font-family: ui-monospace, 'SF Mono', monospace; font-size: 12px;"
                                data-testid="skip-table"
                            >
                                {{ $tableName }}
                            </td>
                            <td
                                class="px-4 py-2 font-mono text-slate-600 dark:text-slate-300"
                                style="font-family: ui-monospace, 'SF Mono', monospace; font-size: 12px;"
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
