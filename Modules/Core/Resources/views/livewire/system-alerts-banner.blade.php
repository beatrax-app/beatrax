{{--
    Persistent banner surfacing un-acknowledged system_alerts rows.

    Variables in scope:
      - $alerts : Illuminate\Database\Eloquent\Collection<int, Modules\Core\Models\SystemAlert>

    The banner is INVISIBLE when calm (no active rows) — the outer
    wrapper renders empty so screen-readers can navigate to the
    landmark, but nothing is painted on screen.

    Severity picks a tone name only. The literal Tailwind class strings
    each tone resolves to live in x-core::alert, which is where the
    content scanner finds them — no `border-{tier}-500` interpolation,
    no PurgeCSS safelist comments, and no per-severity copy of the row.

    A critical row is announced assertively (`role="alert"`, which
    interrupts the reader); a warning or informational row is a polite
    live region instead, so it waits for a pause.

    The banner also names the severity in a word, because the tone was
    the only thing that reported it: the row body is written the same
    way whatever the severity is, so rose and amber were what separated
    an unredacted-token alert from a backup that is a day late.

    Row content is factored into `partials/system-alert-body`, which in
    turn includes `partials/system-alert-message` and
    `partials/system-alert-actions` so the auto-update kinds can render
    multi-button rows (Install / Skip / Release notes for
    `update.available`; Update now / Remind me later for `update.stale`;
    Install on next launch only for `update.critical`).

    Every interpolation uses Blade {{ }} default escaping. Unescaped
    output (raw-output Blade) is forbidden in this view —
    system_alerts.message may carry operator-controlled text that we
    treat as untrusted.
--}}
@use('Modules\Core\Public\Support\Lang')
@use('Modules\Core\Public\Enums\SystemAlertSeverity')
<section
    aria-label="{{ Lang::get('core::alerts.banner_aria') }}"
    {{--
        `px-6` matches the horizontal gutter the main content uses (so
        an alert card lines up with the dashboard's header). `space-y-2`
        stacks multiple alerts compactly. When at least one alert is
        active, `pt-6 pb-8` reserves a comfortable column above + below
        the stack so the banner stops crowding the page header. When the
        app is calm (no active rows), the wrapper renders without
        padding so the empty landmark collapses to zero height — the
        @if guard means the screen-reader landmark is still announced,
        but no visible space is consumed.
    --}}
    @class([
        'px-6 space-y-2',
        'pt-6 pb-8' => count($alerts) > 0,
    ])
>
    @foreach ($alerts as $alert)
        @php
            $isCritical = $alert->severity === SystemAlertSeverity::Critical->value;
            $tone = match ($alert->severity) {
                SystemAlertSeverity::Critical->value => 'danger',
                SystemAlertSeverity::Warning->value => 'warning',
                default => 'neutral',
            };
            // An informational row reports no severity, so it gets no word:
            // absence has to read as "nothing is wrong here" rather than as
            // the severity below the one that is named.
            $severityWord = match ($alert->severity) {
                SystemAlertSeverity::Critical->value => Lang::get('core::alerts.severity.critical'),
                SystemAlertSeverity::Warning->value => Lang::get('core::alerts.severity.warning'),
                default => null,
            };
        @endphp
        <x-core::alert
            :tone="$tone"
            :role="$isCritical ? 'alert' : false"
            :aria-live="$isCritical ? false : 'polite'"
            :aria-atomic="$isCritical ? false : 'true'"
        >
            @if ($severityWord !== null)
                <p class="text-xs font-semibold uppercase tracking-wide">{{ $severityWord }}</p>
            @endif
            @include('core::livewire.partials.system-alert-body', ['alert' => $alert])
        </x-core::alert>
    @endforeach
</section>
