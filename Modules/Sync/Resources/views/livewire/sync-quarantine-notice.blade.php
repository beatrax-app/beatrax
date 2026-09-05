{{--
    The refusals nothing takes again, on the settings screen rather than in the
    developer console. One block per outcome, because the four differ in what
    the reader can do about them and a single count would say none of it.

    Read-only by contract: no control of any kind may appear in this file.
    AQuarantineSurfaceOffersNoWayToApplyWhatItRefusedArchTest fails on one,
    and the reason is that force-applying an operation the merge layer refused
    writes the very data the refusal is evidence about.
--}}

@use('Modules\Core\Public\Support\Lang')
@if ($groups !== [])
    <div class="space-y-3" data-testid="sync-quarantine-notice">
        @foreach ($groups as $group)
            <x-core::alert
                :tone="$group['outcome']->tone()"
                role="status"
                data-testid="quarantine-outcome"
                data-outcome="{{ $group['outcome']->value }}"
            >
                <p class="font-semibold" style="font-feature-settings: 'tnum';">
                    {{ Lang::choice($group['outcome']->summaryKey(), $group['tally']) }}
                </p>
                <p class="mt-1">{{ Lang::get($group['outcome']->bodyKey()) }}</p>
                <p class="mt-1">{{ Lang::get($group['outcome']->actionKey()) }}</p>
                @if ($group['newest'] !== null)
                    <p class="mt-1 text-xs">{{ Lang::get('sync::quarantine.last_seen', ['when' => $group['newest']]) }}</p>
                @endif
            </x-core::alert>
        @endforeach
    </div>
@endif
