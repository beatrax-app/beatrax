<?php

declare(strict_types=1);

// The countdown decremented a counter once per tick, and a window that is not
// in front is clamped to one tick a minute, so it shed one second a minute. A
// retired code still read "Expires in 7:25", and the branch that swaps in the
// replacement panel is reached only when the counter hits zero.
it('derives what is left from the clock, not from a tally of ticks', function (): void {
    $markup = (string) file_get_contents(
        base_path('Modules/Sync/Resources/views/livewire/pairing-flow-modal.blade.php')
    );

    expect($markup)->toContain('Date.now() - startedAt');
    expect($markup)->toContain('startedAt: Date.now()');
    expect($markup)->not->toContain('remaining--');
});

// A clamped tick still fires, so the expiry lands within a minute rather than
// never. Coming back to the window has to settle it at once, or the reader
// looks at a stale number for as long as the throttle lasted.
it('settles the moment the window is looked at again', function (): void {
    $markup = (string) file_get_contents(
        base_path('Modules/Sync/Resources/views/livewire/pairing-flow-modal.blade.php')
    );

    expect($markup)->toContain('x-on:visibilitychange.document="check()"');
});
