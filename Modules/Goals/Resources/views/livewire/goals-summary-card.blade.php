{{--
    Goals summary card — dashboard inline card showing up to 3 nearest-finishing
    active goals. Chrome matches the NetWorthCard section. Empty-state shows a
    calm single-line "No goals yet" copy when the user has no active goals.

    Mini progress bar: 8px height, 80px wide, same 3-state color logic as the
    full goals-page card. Tabular numerics throughout.
--}}

@use('Modules\Core\Public\Navigation\Destination')
@use('Modules\Core\Public\Support\Lang')
@use('Modules\Goals\Internal\Enums\GoalProgressState')

<div class="rounded-lg border border-slate-200 bg-white p-6 dark:border-slate-700 dark:bg-slate-950">
    {{-- Card header --}}
    <div class="flex items-center justify-between">
        <p class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ Lang::get('goals::messages.page.title') }}</p>
        <a
            href="{{ Destination::Goals->url() }}"
            class="tap-link text-xs text-slate-400 hover:text-slate-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:text-slate-500 dark:hover:text-slate-300"
        >{{ Lang::get('goals::messages.summary.see_all') }}</a>
    </div>

    @if (count($goals) === 0)
        {{-- Empty state.

             mt-7 rather than mt-4: this link and the card header's "See all"
             both carry a 44px band, and at 384px their centres were 33px
             apart, so each band was cut to its neighbour's edge and "See all"
             answered a finger over 33px. A tap on its lower half opened the
             goal form instead. The pitch is what has to clear 44.

             inline-block on the link for the same reason as the imports CTA:
             it wraps in Dutch, and .tap-link's band is placed nowhere on an
             inline box split across two lines. --}}
        <p class="mt-7 text-sm text-slate-500 dark:text-slate-400">
            {{ Lang::get('goals::messages.summary.no_goals') }}
            <a
                href="{{ Destination::Goals->url() }}"
                class="tap-link inline-block text-slate-900 underline underline-offset-2 hover:no-underline dark:text-slate-100"
            >{{ Lang::get('goals::messages.summary.add_first') }}</a>
        </p>
    @else
        <ul class="mt-4 space-y-3">
            @foreach ($goals as $row)
                @php
                    $pct = $row->percentComplete();
                    $barWidth = $pct === 0 ? 0 : max(2, $pct);
                @endphp
                {{-- Wraps below sm: the 80px bar, the percentage and the status
                     badge do not shrink, so on a phone the goal name was left
                     ~64px for text needing 113px. --}}
                <li class="flex flex-wrap items-center gap-x-3 gap-y-1 sm:flex-nowrap">
                    <p class="w-full min-w-0 truncate text-sm text-slate-900 sm:w-auto sm:flex-1 dark:text-slate-100">{{ $row->name }}</p>
                    {{-- Mini progress bar: 8px × 80px. `width` is a prop and
                         `shrink-0` a class because two Tailwind width utilities
                         on one element resolve by stylesheet order, not by the
                         order the call site wrote them. --}}
                    <x-core::progress-bar
                        :value="$barWidth"
                        :tone="$row->progressState === GoalProgressState::Overdue->value ? 'warning' : 'positive'"
                        :label="Lang::get('goals::messages.progress.aria', ['name' => $row->name, 'pct' => $pct])"
                        width="w-20"
                        class="shrink-0"
                    />
                    <span class="shrink-0 text-xs text-slate-500 dark:text-slate-400" style="font-variant-numeric: tabular-nums;">{{ $pct }}%</span>
                    <span class="shrink-0 text-xs text-slate-500 dark:text-slate-400">
                        @if ($row->progressState === GoalProgressState::Overdue->value)
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
