<?php

declare(strict_types=1);

// Livewire builds its 500 modal as a <dialog> containing an <iframe> sized
// 100%/100% at padding 0, and puts the close listener on the dialog. A click
// does not cross an iframe boundary, so with no padding the only thing that
// dismisses it is the backdrop — undiscoverable, and on iOS under the system
// edge-swipe areas. This pins the padding that gives the dialog a tappable
// surface of its own.
it('leaves the error modal a surface of its own to be dismissed by', function (): void {
    $css = (string) file_get_contents(base_path('resources/css/app.css'));
    expect($css)->not->toBe('');

    $at = strpos($css, 'dialog#livewire-error');
    expect($at)->not->toBeFalse('no rule targets Livewire\'s error modal.');

    $open = strpos($css, '{', (int) $at);
    $close = strpos($css, '}', (int) $open);
    expect($open)->not->toBeFalse();
    expect($close)->not->toBeFalse();

    $body = substr($css, (int) $open, (int) $close - (int) $open);

    expect($body)->toContain('padding:');
    expect($body)->not->toContain('padding: 0');
});
