{{--
    The refusals this device recorded, on the settings screen rather than in the
    developer console. One block per outcome for the ones nothing takes again,
    because the four differ in what the reader can do about them and a single
    count would say none of it — and one block beneath them for the half a pass
    can still answer, which is a wait rather than a loss and is worded as one.

    The status line above links here by id, so this element is the destination
    of "see what is held" and stays in the DOM whether or not it has a block
    to draw.

    Read-only by contract: no control of any kind may appear in this file.
    AQuarantineSurfaceOffersNoWayToApplyWhatItRefusedArchTest fails on one,
    and the reason is that force-applying an operation the merge layer refused
    writes the very data the refusal is evidence about.
--}}

@use('Modules\Core\Public\Support\Lang')
{{-- Livewire requires a single root element on every render, and this
     component renders nothing at all when there is nothing to report,
     which is the ordinary case. Without the wrapper an install with a
     clean quarantine answers 500 rather than a page. --}}
<div id="sync-refused-changes">
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

    {{-- Names a condition and never an act: what ends one of these is a later
         pass finding what it was missing, which is not something the reader
         can press, and copy that implies otherwise is a button that is not
         there. --}}
    @if ($held !== null)
        <x-core::alert
            tone="info"
            role="status"
            class="mt-3"
            data-testid="quarantine-held"
        >
            <p class="font-semibold" style="font-feature-settings: 'tnum';">
                {{ Lang::choice('sync::quarantine.held.summary', $held['tally']) }}
            </p>
            <p class="mt-1">{{ Lang::get('sync::quarantine.held.body') }}</p>
            <p class="mt-1">{{ Lang::get('sync::quarantine.held.action') }}</p>
            @if ($held['newest'] !== null)
                <p class="mt-1 text-xs">{{ Lang::get('sync::quarantine.last_seen', ['when' => $held['newest']]) }}</p>
            @endif
        </x-core::alert>
    @endif
</div>
