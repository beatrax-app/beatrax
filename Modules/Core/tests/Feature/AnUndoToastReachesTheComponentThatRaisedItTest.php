<?php

declare(strict_types=1);

use Modules\Core\Public\Http\Livewire\Concerns\DispatchesToast;

// The host read detail.message and nothing else, so every Undo in the app was
// a word in a sentence: the action and its payload were dispatched into a
// listener that never looked at them, and three modules were shipping a
// promise nothing could keep.

it('renders an undo control on a toast that carries one', function (): void {
    $markup = (string) view('core::components.toast-host')->render();

    expect($markup)
        ->toContain('data-testid="toast-undo"')
        ->toContain('x-show="t.undoAction"');
});

it('calls the action back on the component the toast names', function (): void {
    $markup = (string) view('core::components.toast-host')->render();

    // The browser event says nothing about where it came from, so the id the
    // trait sends is the only route back into the component that can undo.
    expect($markup)
        ->toContain('window.Livewire.find(t.componentId)')
        ->toContain('target.call(t.undoAction, t.undoPayload)');
});

it('keeps the undo out of the reader way on an ordinary toast', function (): void {
    $markup = (string) view('core::components.toast-host')->render();

    expect($markup)->toContain('undoAction: (detail && detail.undoAction) || null');
});

it('sends the component id along with the action the button has to call', function (): void {
    $source = (string) file_get_contents(
        (string) (new ReflectionClass(DispatchesToast::class))->getFileName(),
    );

    expect($source)->toContain('componentId: $this->getId()');
});
