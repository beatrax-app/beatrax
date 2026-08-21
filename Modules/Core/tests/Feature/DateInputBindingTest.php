<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

// Livewire implements `wire:model` by applying Alpine's `x-model` to the
// element, so the first shape — an sr-only input carrying both — had two
// bindings claiming it. Alpine's still-empty copy won and wiped the server's
// value on every render; a reconcile page dated today rendered as "—".

it('puts the caller wire:model on the root and entangles it with x-modelable', function (): void {
    $html = Blade::render('<x-core::date-input wire:model.live="statementDate" field-id="rc-date" />');

    expect($html)->toContain('x-modelable="value"')
        ->and($html)->toContain('wire:model.live="statementDate"');

    // The binding belongs to the element that also declares x-modelable, and
    // that element is the x-data root. If the two ever land on different
    // elements the entanglement silently does nothing.
    $rootTag = mb_substr($html, 0, (int) mb_strpos($html, '>'));

    expect($rootTag)->toContain('x-data=')
        ->and($rootTag)->toContain('x-modelable="value"')
        ->and($rootTag)->toContain('wire:model.live="statementDate"');
});

it('carries no second binding for the same value', function (): void {
    $html = Blade::render('<x-core::date-input wire:model="date" />');

    // `x-modelable` is the only x-model-family directive in the component.
    // A literal `x-model=` anywhere means something else is claiming the
    // value too.
    expect($html)->not->toContain('x-model="')
        ->and($html)->not->toContain('x-model.');
});

it('keeps the caller field id and aria attributes on the button a label points at', function (): void {
    $html = Blade::render(
        '<x-core::date-input field-id="goal-date" wire:model="targetDate" aria-invalid="true" aria-describedby="goal-date-error" />'
    );

    // The id must be on the control the user actually clicks, because the
    // caller's <label for="…"> points at it. aria-invalid on the wrapper
    // would describe a div no assistive technology announces.
    expect($html)->toMatch('/<button[^>]*id="goal-date"/')
        ->and($html)->toMatch('/<button[^>]*aria-invalid="true"/')
        ->and($html)->toMatch('/<button[^>]*aria-describedby="goal-date-error"/');
});

it('hands the client the active locale month names, weekday order and short-date pattern', function (): void {
    app()->setLocale('nl');

    $html = Blade::render('<x-core::date-input wire:model="date" />');

    // Dutch writes 31-08-2026 and starts its week on Monday. Both come from
    // Carbon rather than ICU: the mobile build bundles ICU with English-only
    // data, so an ICU-routed formatter returns English or throws.
    expect($html)->toContain('DD-MM-YYYY')
        ->and($html)->toContain('augustus')
        ->and($html)->toContain('firstDow: 1');
});
