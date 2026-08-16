{{--
    Goals summary card — dashboard inline card showing up to 3 nearest-finishing
    active goals. Chrome matches the NetWorthCard section. Empty-state shows a
    calm single-line "No goals yet" copy when the user has no active goals.

    Mini progress bar: 4px height, 80px wide, same 3-state color logic as the
    full goals-page card. Tabular numerics throughout.
--}}

@use('Modules\Core\Public\Support\Lang')
@php
    $progressColor = [
        'in_progress' => 'bg-emerald-500 dark:bg-emerald-400',
        'reached'     => 'bg-emerald-500 dark:bg-emerald-400',
        'overdue'     => 'bg-amber-500 dark:bg-amber-400',
    ];
@endphp

<div class="rounded-lg border border-slate-200 bg-white p-6 dark:border-slate-700 dark:bg-slate-950">
    {{-- Card header --}}
    <div class="flex items-center justify-between">
        <p class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('goals::messages.page.title') }}</p>
        <a
            href="{{ route('goals.index') }}"
            class="tap-link text-xs text-slate-400 hover:text-slate-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:text-slate-500 dark:hover:text-slate-300"
        >{{ Lang::get('goals::messages.summary.see_all') }}</a>
    </div>

    @if (count($goals) === 0)
        {{-- Empty state --}}
        <p class="mt-4 text-sm text-slate-500 dark:text-slate-400">
            {{ Lang::get('goals::messages.summary.no_goals') }}
            <a
                href="{{ route('goals.index') }}"
                class="text-slate-900 underline underline-offset-2 hover:no-underline dark:text-slate-100"
            >{{ Lang::get('goals::messages.summary.add_first') }}</a>
        </p>
    @else
        <ul class="mt-4 space-y-3">
            @foreach ($goals as $row)
                @php
                    $pct = $row->targetMinor > 0
                        ? (int) round(min(100, $row->fractionComplete * 100))
                        : 0;
                    $barWidth = $pct === 0 ? 0 : max(2, $pct);
                    $color = $progressColor[$row->progressState] ?? $progressColor['in_progress'];
                @endphp
                {{-- Wraps below sm: the 80px bar, the percentage and the status
                     badge do not shrink, so on a phone the goal name was left
                     ~64px for text needing 113px. --}}
                <li class="flex flex-wrap items-center gap-x-3 gap-y-1 sm:flex-nowrap">
                    <p class="w-full min-w-0 truncate text-sm text-slate-900 sm:w-auto sm:flex-1 dark:text-slate-100">{{ $row->name }}</p>
                    {{-- Mini progress bar: 4px × 80px --}}
                    <div
                        class="h-1 w-20 shrink-0 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700"
                        role="progressbar"
                        aria-valuenow="{{ $pct }}"
                        aria-valuemin="0"
                        aria-valuemax="100"
                        aria-label="{{ Lang::get('goals::messages.progress.aria', ['name' => $row->name, 'pct' => $pct]) }}"
                    >
                        <div class="h-1 rounded-full {{ $color }}" style="width: {{ $barWidth }}%;"></div>
                    </div>
                    <span class="shrink-0 text-xs text-slate-500 dark:text-slate-400" style="font-variant-numeric: tabular-nums;">{{ $pct }}%</span>
                    <span class="shrink-0 text-xs text-slate-500 dark:text-slate-400">
                        @if ($row->progressState === 'overdue')
                            <span class="inline-flex items-center rounded-full bg-amber-100 px-1.5 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-900/40 dark:text-amber-300">{{ Lang::get('goals::messages.status.overdue') }}</span>
                        @elseif ($row->projectedFinishDate !== null)
                            · {{ \Carbon\CarbonImmutable::parse($row->projectedFinishDate)->translatedFormat('d M \'y') }}
                        @endif
                    </span>
                </li>
            @endforeach
        </ul>
    @endif
</div>
