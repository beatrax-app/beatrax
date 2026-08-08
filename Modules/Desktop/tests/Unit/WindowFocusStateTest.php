<?php

declare(strict_types=1);

use Modules\Desktop\Internal\Native\WindowFocusState;

/*
 * `WindowFocusState` is a tiny singleton holding the focused/unfocused
 * flag for the Beatrax main window. The provider subscribes to
 * NativePHP's `WindowFocused` / `WindowBlurred` events and flips the
 * flag; `DispatchOsNotification` reads it to decide whether to fire an
 * OS notification or let the in-app SystemAlertsBanner handle the
 * event.
 *
 * Pure-state object — always automated, no NativePHP facade fakes
 * needed.
 */

it('defaults to focused on construction (D-13 conservative default)', function (): void {
    // The freshly-launched Beatrax window opens directly in front of
    // the user, so the conservative assumption is "focused" until the
    // first WindowBlurred event arrives. Defaulting the other way
    // (unfocused) would let every notification fired during the boot-
    // up race pop an OS toast on top of the in-app banner the user
    // is already looking at — exactly the noise the D-13 focus-gate
    // exists to prevent.
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
