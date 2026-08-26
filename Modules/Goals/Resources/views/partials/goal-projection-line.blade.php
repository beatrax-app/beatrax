{{--
    One goal's projected-finish line.

    Variables in scope:
      - $row   : Modules\Goals\Public\Dto\GoalProgressRow
      - $class : classes for the wrapping <p> — the phone list and the
                 desktop card place this line differently

    The phone list carried a second copy of this chain and that copy had
    lost the "(projection)" qualifier the desktop card appends to a
    beyond-horizon estimate, so a phone reader saw a bare date where a
    desktop reader saw an estimate. One chain, so the two surfaces can no
    longer disagree about what a date means.
--}}
@use('Modules\Core\Public\Support\Lang')
@use('Modules\Goals\Internal\Enums\GoalProgressState')
<p class="{{ $class }}">
    @if ($row->progressState === GoalProgressState::Reached->value)
        {{ Lang::get('goals::messages.projection.target_reached') }}
    @elseif ($row->isCompleted())
        {{ Lang::get('goals::messages.projection.closed_short') }}
    @elseif ($row->projectedFinishDate === null && $row->contributedMinor <= 0)
        {{ Lang::get('goals::messages.projection.add_contributions') }}
    @elseif ($row->projectedFinishDate === null && $row->projectionStalled)
        {{ Lang::get('goals::messages.projection.no_recent_contributions') }}
    @elseif ($row->projectedFinishDate === null)
        {{ Lang::get('goals::messages.projection.not_enough_history') }}
    @elseif ($row->projectionBeyondHorizon)
        {{ Lang::get('goals::messages.projection.est', ['date' => \Carbon\CarbonImmutable::parse($row->projectedFinishDate)->isoFormat('D MMM YYYY')]) }}
        <span class="text-slate-400 dark:text-slate-500">{{ Lang::get('goals::messages.projection.projection_note') }}</span>
    @else
        {{ Lang::get('goals::messages.projection.projected', ['date' => \Carbon\CarbonImmutable::parse($row->projectedFinishDate)->isoFormat('D MMM YYYY')]) }}
    @endif
</p>
