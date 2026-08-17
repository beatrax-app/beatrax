<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

/*
 * The date field's binding contract.
 *
 * `x-core::date-input` replaced `<input type="date">` across the app, and how
 * it hands its value to Livewire is the whole of its job — a calendar that
 * looks right and writes nowhere is worse than the untranslated native control
 * it replaced, because it fails silently.
 *
 * The first shape of this component put the caller's `wire:model` on an
 * `sr-only` input that ALSO carried `x-model="value"`. That looked harmless
 * and was not: Livewire implements `wire:model` by applying Alpine's `x-model`
 * to the element, so the input had two bindings claiming it. Alpine's copy —
 * still empty, because `x-data`'s `init()` runs before the subtree's own
 * `x-ref` is registered — won, and wiped the server's value out of the field
 * on every render. A reconcile page whose statement date is today rendered as
 * "—".
 *
 * These assert the shape that cannot regress into that: exactly one binding,
 * on the root, entangled with the component's own property by `x-modelable`.
 */

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
