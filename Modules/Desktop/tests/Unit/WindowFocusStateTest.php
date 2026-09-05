<?php

declare(strict_types=1);

use Modules\Desktop\Internal\Listeners\TrackWindowFocus;
use Modules\Desktop\Internal\Native\WindowFocusState;

it('defaults to focused on construction (the conservative default)', function (): void {
    // A freshly launched window opens in front of the user, so "focused" is the
    // conservative default until the first blur arrives. Defaulting to unfocused
    // would let every notification fired during the boot-up race pop an OS toast
    // over the in-app banner the user is already looking at.
    $state = new WindowFocusState;

    expect($state->isFocused())->toBeTrue();
});

it('flips to unfocused when markBlurred() is called', function (): void {
    $state = new WindowFocusState;
    $state->markBlurred();

    expect($state->isFocused())->toBeFalse();
});

it('flips back to focused when markFocused() is called after a blur', function (): void {
    $state = new WindowFocusState;
    $state->markBlurred();
    $state->markFocused();

    expect($state->isFocused())->toBeTrue();
});

it('is bound as a singleton so focus/blur subscribers + listeners share one instance', function (): void {
    $first = app(WindowFocusState::class);
    $second = app(WindowFocusState::class);

    expect($first)->toBe($second);
});

// The shell's focus events reach this through TrackWindowFocus, which exists as
// a named class rather than the two closures it replaces so the shell-event arch
// guard can walk it. These pin that the swap kept the behaviour.
it('takes a shell focus event through the listener that replaced the closures', function (): void {
    /** @var WindowFocusState $state */
    $state = app(WindowFocusState::class);
    $state->markBlurred();

    app(TrackWindowFocus::class)->handleFocused();

    expect($state->isFocused())->toBeTrue();
});

it('takes a shell blur event through the same listener', function (): void {
    /** @var WindowFocusState $state */
    $state = app(WindowFocusState::class);
    $state->markFocused();

    app(TrackWindowFocus::class)->handleBlurred();

    expect($state->isFocused())->toBeFalse();
});
