<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Desktop\Internal\Listeners\TrackWindowFocus;
use Modules\Desktop\Internal\Native\ShellState;
use Modules\Desktop\Internal\Native\WindowFocusState;

uses(RefreshDatabase::class);

// A fresh object every time, on purpose: the shell writes this flag from one PHP
// process and the window reads it from another, so an instance that remembered
// anything would be answering a question nobody asked it.
function focusState(): WindowFocusState
{
    return new WindowFocusState(app(ShellState::class));
}

it('reads focused while nothing has been recorded (the conservative default)', function (): void {
    // A freshly launched window opens in front of the user, so "focused" is the
    // conservative default until the first blur arrives. Defaulting to unfocused
    // would let every notification fired during the boot-up race pop an OS toast
    // over the in-app banner the user is already looking at.
    expect(focusState()->isFocused())->toBeTrue();
});

it('flips to unfocused when markBlurred() is called', function (): void {
    focusState()->markBlurred();

    expect(focusState()->isFocused())->toBeFalse();
});

it('flips back to focused when markFocused() is called after a blur', function (): void {
    focusState()->markBlurred();
    focusState()->markFocused();

    expect(focusState()->isFocused())->toBeTrue();
});

it('is read back by an instance that never saw the write, which is every reader there is', function (): void {
    focusState()->markBlurred();

    expect(focusState()->isFocused())->toBeFalse(
        'The blur has to survive the request that recorded it. Held on the '.
        'object it did not: the shell posts each window event to its own PHP '.
        'process, so every reader got the constructed default instead.',
    );
});

// The shell's focus events reach this through TrackWindowFocus, which exists as
// a named class rather than the two closures it replaces so the shell-event arch
// guard can walk it. These pin that the swap kept the behaviour.
it('takes a shell focus event through the listener that replaced the closures', function (): void {
    focusState()->markBlurred();

    app(TrackWindowFocus::class)->handleFocused();

    expect(focusState()->isFocused())->toBeTrue();
});

it('takes a shell blur event through the same listener', function (): void {
    focusState()->markFocused();

    app(TrackWindowFocus::class)->handleBlurred();

    expect(focusState()->isFocused())->toBeFalse();
});

it('drops a blur the last launch left behind when the shell reports it has booted', function (): void {
    focusState()->markBlurred();

    app(TrackWindowFocus::class)->handleBooted();

    expect(focusState()->isFocused())->toBeTrue();
});
