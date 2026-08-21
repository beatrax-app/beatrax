{{--
    Persistent banner surfacing un-acknowledged system_alerts rows.

    Variables in scope:
      - $alerts : Illuminate\Database\Eloquent\Collection<int, Modules\Core\Models\SystemAlert>

    The banner is INVISIBLE when calm (no active rows) — the outer
    wrapper renders empty so screen-readers can navigate to the
    landmark, but nothing is painted on screen.

    Severity branches are three explicit Blade snippets containing
    literal Tailwind class strings. Tailwind's content scanner needs
    direct string occurrences in source — no `border-{tier}-500`
    interpolation, no PurgeCSS safelist comments.

    Per-row actions are factored into `partials/system-alert-actions`
    so the auto-update kinds can render multi-button rows
    (Install / Skip / Release notes for `update.available`; Update
    now / Remind me later for `update.stale`; Install on next launch
    only for `update.critical`) without duplicating the layout across
    the three severity branches.

    Every interpolation uses Blade {{ }} default escaping. Unescaped
    output (raw-output Blade) is forbidden in this view —
    system_alerts.message may carry operator-controlled text that we
    treat as untrusted.

    Each row stacks below `sm` and only sits the actions beside the
    message from `sm` up. The buttons do not shrink, so on a phone a
    side-by-side row left the message a ~130px column and broke one
    sentence over six lines.
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
        @switch ($alert->severity)
            @case (SystemAlertSeverity::Critical->value)
                <x-core::alert tone="danger" role="alert">
                    <div class="flex flex-col items-start gap-3 sm:flex-row sm:justify-between sm:gap-4">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium">@include('core::livewire.partials.system-alert-message', ['alert' => $alert])</p>
                            <p class="mt-1 text-xs" style="font-variant-numeric: tabular-nums;">
                                {{ $alert->created_at->translatedFormat('d M Y · H:i') }}
                                <span class="mx-1">·</span>
                                #{{ $alert->id }}
                            </p>
                        </div>
                        <div class="shrink-0">
                            @include('core::livewire.partials.system-alert-actions', ['alert' => $alert])
                        </div>
                    </div>
                </x-core::alert>
                @break
            @case (SystemAlertSeverity::Warning->value)
                <x-core::alert tone="warning" aria-live="polite" aria-atomic="true">
                    <div class="flex flex-col items-start gap-3 sm:flex-row sm:justify-between sm:gap-4">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium">@include('core::livewire.partials.system-alert-message', ['alert' => $alert])</p>
                            <p class="mt-1 text-xs" style="font-variant-numeric: tabular-nums;">
                                {{ $alert->created_at->translatedFormat('d M Y · H:i') }}
                                <span class="mx-1">·</span>
                                #{{ $alert->id }}
                            </p>
                        </div>
                        <div class="shrink-0">
                            @include('core::livewire.partials.system-alert-actions', ['alert' => $alert])
                        </div>
                    </div>
                </x-core::alert>
                @break
            @default
                <x-core::alert tone="neutral" aria-live="polite" aria-atomic="true">
                    <div class="flex flex-col items-start gap-3 sm:flex-row sm:justify-between sm:gap-4">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium">@include('core::livewire.partials.system-alert-message', ['alert' => $alert])</p>
                            <p class="mt-1 text-xs" style="font-variant-numeric: tabular-nums;">
                                {{ $alert->created_at->translatedFormat('d M Y · H:i') }}
                                <span class="mx-1">·</span>
                                #{{ $alert->id }}
                            </p>
                        </div>
                        <div class="shrink-0">
                            @include('core::livewire.partials.system-alert-actions', ['alert' => $alert])
                        </div>
                    </div>
                </x-core::alert>
        @endswitch
    @endforeach
</section>
