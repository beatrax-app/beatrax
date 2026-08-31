{{--
    One goal's target date, rendered only when the row carries one.

    Variables in scope:
      - $row   : Modules\Goals\Public\Dto\GoalProgressRow
      - $class : classes for the wrapping <p> — the phone list and the
                 desktop card place this line differently

    The create form refuses to save a goal without this date, so both
    lists show it; only the type scale around it differs.
--}}
@use('Modules\Core\Public\Support\Lang')
@use('Modules\Goals\Public\Dto\GoalProgressRow')
@if ($row->targetDate !== '')
    <p class="{{ $class }}">
        {{ Lang::get('goals::messages.card.target_date', ['date' => \Carbon\CarbonImmutable::parse($row->targetDate)->isoFormat(GoalProgressRow::DATE_FORMAT)]) }}
    </p>
@endif
