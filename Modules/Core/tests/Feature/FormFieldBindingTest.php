<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;

/*
 * The labelled-field component's forwarding contract.
 *
 * `x-core::form-field` replaced the hand-written label + control + error block
 * across the sign-in, sign-up, password, settings, import and backup screens.
 * Every one of those call sites binds through Livewire, and the modifier on
 * that binding is what decides WHEN the server hears about a keystroke:
 * `wire:model` waits for the next roundtrip, `.live` fires per input, `.blur`
 * waits for focus to leave, `.debounce.300ms` throttles.
 *
 * A component that took the property NAME as a prop and re-rendered
 * `wire:model="{$prop}"` would drop every one of those modifiers while still
 * rendering a field that looks correct and passes a render test. So the
 * caller writes the real directive on the component tag and the whole wire:*
 * attribute is forwarded verbatim. These assert exactly that, plus the label
 * and error wiring an accessible form depends on.
 */

it('forwards every wire:model modifier to the rendered control verbatim', function (string $directive): void {
    $html = Blade::render('<x-core::form-field name="query" label="Search" '.$directive.'="query" />');

    expect($html)->toContain($directive.'="query"');
})->with([
    'wire:model',
    'wire:model.live',
    'wire:model.blur',
    'wire:model.lazy',
    'wire:model.live.debounce.300ms',
    'wire:model.live.debounce.400ms',
]);

it('renders no bare wire:model when the caller supplied a modified one', function (): void {
    $html = Blade::render('<x-core::form-field name="query" label="Search" wire:model.live.debounce.300ms="query" />');

    // The whole failure mode this component exists to avoid: a second,
    // modifier-less binding written from a prop would sit alongside the real
    // one and win, turning a debounced field into a per-keystroke roundtrip.
    expect(substr_count($html, 'wire:model'))->toBe(1)
        ->and($html)->not->toContain('wire:model="query"');
});

it('switches the rendered element on the type prop and carries the slot into it', function (): void {
    $select = Blade::render(
        '<x-core::form-field name="baseCurrency" label="Base" type="select" wire:model.blur="baseCurrency">'
        .'<option value="EUR">EUR</option></x-core::form-field>'
    );
    $textarea = Blade::render('<x-core::form-field name="notes" label="Notes" type="textarea" rows="3" wire:model="notes" />');
    $number = Blade::render('<x-core::form-field name="periodStartDay" label="Day" type="number" min="1" max="28" wire:model="periodStartDay" />');

    expect($select)->toContain('<select')
        ->and($select)->toContain('<option value="EUR">EUR</option>')
        ->and($select)->toContain('wire:model.blur="baseCurrency"')
        ->and($select)->not->toContain('<input')
        ->and($textarea)->toContain('<textarea')
        ->and($textarea)->toContain('rows="3"')
        ->and($number)->toContain('type="number"')
        ->and($number)->toContain('min="1"')
        ->and($number)->toContain('max="28"');
});

it('wires the label for= to the control id, defaulting the id to the field name', function (): void {
    $defaulted = Blade::render('<x-core::form-field name="username" label="Username" wire:model="username" />');
    $explicit = Blade::render('<x-core::form-field field-id="new-password" name="newPassword" label="New password" type="password" wire:model="newPassword" />');

    expect($defaulted)->toContain('for="username"')
        ->and($defaulted)->toContain('id="username"')
        ->and($explicit)->toContain('for="new-password"')
        ->and($explicit)->toContain('id="new-password"')
        ->and($explicit)->toContain('name="newPassword"');
});

it('points aria-describedby at the error and marks the control invalid when the bag has one', function (): void {
    $bag = new ViewErrorBag;
    $bag->put('default', new MessageBag(['periodStartDay' => ['Pick a day between 1 and 28.']]));
    View::share('errors', $bag);

    $html = Blade::render('<x-core::form-field name="periodStartDay" label="Day" hint="Statement day." wire:model="periodStartDay" />');
    $clean = Blade::render('<x-core::form-field name="baseCurrency" label="Base" wire:model="baseCurrency" />');

    expect($html)->toContain('aria-describedby="periodStartDay-hint periodStartDay-error"')
        ->and($html)->toContain('aria-invalid="true"')
        ->and($html)->toContain('id="periodStartDay-error"')
        ->and($html)->toContain('Pick a day between 1 and 28.')
        ->and($clean)->not->toContain('aria-invalid')
        ->and($clean)->not->toContain('aria-describedby');
});

it('keeps a caller-named descriptor as the field description', function (): void {
    // The sign-up password field points at the live requirement checklist,
    // which sits outside this component. That reference has to survive, or the
    // list is announced to nobody.
    $html = Blade::render(
        '<x-core::form-field name="password" label="Password" type="password" hint="Twelve characters." '
        .'aria-describedby="password-requirements" wire:model="password" />'
    );

    expect($html)->toContain('aria-describedby="password-requirements"')
        ->and($html)->not->toContain('aria-describedby="password-requirements password-hint"')
        ->and($html)->toContain('id="password-hint"');
});

it('appends caller classes to the shared control class rather than replacing it', function (): void {
    $html = Blade::render('<x-core::form-field name="recoveryCode" label="Code" class="font-mono" wire:model="recoveryCode" />');

    expect($html)->toContain('bg-slate-50')
        ->and($html)->toContain('focus-visible:ring-slate-900')
        ->and($html)->toContain('font-mono');
});
