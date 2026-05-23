<?php

declare(strict_types=1);

use Modules\Desktop\Internal\Native\WindowFocusState;

/*
 * `WindowFocusState` is a tiny singleton holding the focused/unfocused
 * flag for the diederik main window. The provider subscribes to
 * NativePHP's `WindowFocused` / `WindowBlurred` events and flips the
 * flag; `DispatchOsNotification` reads it to decide whether to fire an
 * OS notification or let the in-app SystemAlertsBanner handle the
 * event.
 *
 * Pure-state object — always automated, no NativePHP facade fakes
 * needed.
 */

it('defaults to unfocused on construction', function (): void {
    $state = new WindowFocusState;

    expect($state->isFocused())->toBeFalse();
});

it('flips to focused when markFocused() is called', function (): void {
    $state = new WindowFocusState;
    $state->markFocused();

    expect($state->isFocused())->toBeTrue();
});

it('flips back to unfocused when markBlurred() is called', function (): void {
    $state = new WindowFocusState;
    $state->markFocused();
    $state->markBlurred();

    expect($state->isFocused())->toBeFalse();
});

it('is bound as a singleton so focus/blur subscribers + listeners share one instance', function (): void {
    $first = app(WindowFocusState::class);
    $second = app(WindowFocusState::class);

    expect($first)->toBe($second);
});
