@props([
    'disclosure',      // Required. The figure's Modules\FX\Public\Dto\ConversionDisclosure, or null where it converted nothing.
    'id',              // Required. Unique on the page — it becomes the popover's id and its anchor name.
    'label' => null,   // The figure this is about, already localised, for the trigger's accessible name.
    'onlineRates' => null,  // Whether online rate fetching is on, where the surface knows it; null leaves the note neutral.
    'flat' => false,   // A print target: the rate lines render inline instead of behind a popover.
])

@use('Carbon\CarbonImmutable')
@use('Modules\Core\Public\Support\Fmt')
@use('Modules\Core\Public\Support\Lang')

{{--
    What one converted figure says about its own conversion: the rates that
    built it, their source, the day they were published, and the codes no rate
    reached.

    It exists once because it existed nowhere. Thirty-one renderings across
    twenty-two templates drew the "not converted" half and not one of them a
    rate — a ¥480,000 expense moved the dashboard Out tile by EUR 3,016.97 at a
    bundled rate published ninety-nine days earlier, and the tile said only
    "€5,118.79". The cause was structural rather than a missing label: the
    rate, its source and its date were dropped one call before the surface, so
    no surface could have disclosed them.

    A future converting surface therefore passes ONE prop, and null is a valid
    value: a figure that converted nothing renders nothing. The disclosure comes
    off ConvertedTotal::disclosure(), or off ConversionDisclosure::of() where
    the surface holds the rate set itself.

    The age is Carbon's own relative phrase rather than a sentence of ours: it
    is localised in all twenty-six languages without a plural rule per
    language, and the distance between "3 days ago" and "3 months ago" is the
    whole point of showing it — a bundled snapshot is the DEFAULT path on a
    fresh install, not an edge case.

    The panel is a native [popover] driven by popovertarget, so nothing here
    depends on JavaScript or on hover: `title` is inert on both shipped phones,
    which is why help-tip is built the same way. It is a sibling of the line
    rather than a child of it, because the caller's own colour and type classes
    land on the line and would otherwise cascade into the panel.

    **A disclosure that carries rates must not be placed inside a `<p>`**: the
    popover is a `<div>`, and a block start tag closes an open paragraph in the
    parser, which moves everything after it out of the paragraph. One with no
    rates — an exclusion list on its own, which is what a net-worth account
    line or a report's account line hands over — draws no popover at all and is
    phrasing content, so it may finish a sentence.

    `flat` is for the PDF export. dompdf implements neither `[popover]` nor the
    UA rule that hides one, so the panel would print as unstyled text after the
    table and the trigger as an empty box. A document a reader keeps has room
    for the lines themselves, so it gets them inline and no affordance at all.

    `.docs/features/fx/architecture.md#a-converted-figure-carries-the-rate-that-made-it`
    holds the seam this reads from.
--}}
@php
    $fxPanelId = 'fx-'.$id;

    $fxNothingToSay = $disclosure === null || $disclosure->isEmpty();

    $fxDated = static fn (?CarbonImmutable $when): string => $when === null
        ? ''
        : Lang::get('core::fx.as_of_age', [
            'date' => Fmt::shortDate($when),
            'ago' => $when->diffForHumans(),
        ]);
@endphp

@unless ($fxNothingToSay)
    <span {{ $attributes }} data-fx-disclosure>
        @if ($disclosure->hasRates())
            <span data-fx-rates="{{ count($disclosure->rates) }}">{{ Lang::get('core::fx.global_rates', [
                'date' => $fxDated($disclosure->asOf()),
                'source' => $disclosure->sourceLabel(),
            ]) }}</span>
            @unless ($flat)
            <button
                type="button"
                class="fx-disclosure-trigger fx-disclosure-trigger--inline"
                style="anchor-name: --{{ $fxPanelId }};"
                popovertarget="{{ $fxPanelId }}"
                aria-label="{{ $label === null ? Lang::get('core::fx.rate_details') : Lang::get('core::fx.rate_details_for', ['name' => $label]) }}"
            >
                <span class="fx-icon {{ $disclosure->isStale() ? 'fx-icon--stale' : '' }}" aria-hidden="true"></span>
            </button>
            @endunless
        @endif

        @if ($disclosure->isPartial())
            <span data-not-converted="true">{{ Lang::get('core::money.not_converted', ['list' => $disclosure->unconvertedList()]) }}</span>
        @endif
    </span>

    @if ($disclosure->hasRates())
        @php($fxStaleNote = $disclosure->staleNote($onlineRates))
        @if ($flat)
            <div data-fx-rates-detail>
                @include('core::components.partials.fx-rate-lines', ['disclosure' => $disclosure, 'fxDated' => $fxDated, 'fxStaleNote' => $fxStaleNote])
            </div>
        @else
            <div
                popover
                id="{{ $fxPanelId }}"
                class="fx-popover"
                style="position-anchor: --{{ $fxPanelId }}; position-area: bottom span-right; position-try-fallbacks: flip-inline, flip-block, flip-inline flip-block; margin: 6px 0 0;"
            >
                @include('core::components.partials.fx-rate-lines', ['disclosure' => $disclosure, 'fxDated' => $fxDated, 'fxStaleNote' => $fxStaleNote])
            </div>
        @endif
    @endif
@endunless
