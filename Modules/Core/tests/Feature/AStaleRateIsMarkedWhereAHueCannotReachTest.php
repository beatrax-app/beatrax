<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Blade;
use Modules\Core\Public\Support\PatternScan;
use Modules\FX\Public\Dto\ConversionDisclosure;
use Modules\FX\Public\Dto\RateSet;
use Modules\FX\Public\Dto\RateUsed;
use Modules\FX\Public\Support\BundledRates;
use Tests\Helpers\CssRule;

// The stale-rate marker was an amber ring where a fresh one is slate, and
// nothing else. Take the hue away and the two rings are 1.51:1 apart in light
// and 1.45:1 in dark — the amber TINT inside them 1.11:1 and 1.21:1 — so a
// reader who cannot see the amber was shown two identical circles. The trigger
// they sit in was named "Rate details" in both states, so the screen reader
// was told nothing either.

/** Named for this file: Pest loads every test into one global namespace. */
function fxStaleMarkerDisclosureFor(bool $stale): ConversionDisclosure
{
    return ConversionDisclosure::of(RateSet::empty('EUR')->with(new RateUsed(
        from: 'JPY',
        to: 'EUR',
        rate: '0.00628536',
        source: $stale ? BundledRates::SOURCE : 'ecb',
        asOf: CarbonImmutable::parse($stale ? '2026-06-05' : '2026-09-12'),
        isStale: $stale,
    )));
}

function fxStaleMarkerTriggerIn(string $html): string
{
    $trigger = PatternScan::all('~<button\b[^>]*fx-disclosure-trigger[^>]*>.*?</button>~s', $html);

    return $trigger[0][0] ?? '';
}

beforeEach(fn () => CarbonImmutable::setTestNow('2026-09-12 09:00:00'));

afterEach(fn () => CarbonImmutable::setTestNow(null));

it('says which state the marker is in, in the one reading a screen reader gets', function (): void {
    $stale = fxStaleMarkerDisclosureFor(true);
    $note = $stale->staleNote(null);

    expect($note)->not->toBeNull('The fixture is not stale, so the assertion below proves nothing.');

    $trigger = fxStaleMarkerTriggerIn(Blade::render(
        '<x-core::fx-disclosure :disclosure="$d" id="probe" />',
        ['d' => $stale],
    ));

    expect($trigger)->toContain((string) $note);
});

// The fresh trigger must NOT carry it, or the sentence stops meaning anything.
it('leaves a rate fetched this morning named for its subject alone', function (): void {
    $fresh = fxStaleMarkerDisclosureFor(false);

    expect($fresh->isStale())->toBeFalse()
        ->and($fresh->staleNote(null))->toBeNull();

    $trigger = fxStaleMarkerTriggerIn(Blade::render(
        '<x-core::fx-disclosure :disclosure="$d" id="probe" />',
        ['d' => $fresh],
    ));

    expect($trigger)
        ->toContain('aria-label="Rate details"')
        ->and($trigger)->not->toContain('fx-icon--stale');
});

// The visual half. A ring that fills in is a difference greyscale keeps; the
// amber that replaced a paler amber is the reinforcement, not the signal.
it('fills the ring in rather than only tinting it', function (): void {
    $css = (string) file_get_contents(base_path('resources/css/app.css'));

    expect(CssRule::blockFor($css, '.fx-icon {'))->toContain('border: 1.5px solid currentColor;')
        ->and(CssRule::blockFor($css, '.fx-icon--stale {'))->toContain('background: var(--color-amber);');
});
