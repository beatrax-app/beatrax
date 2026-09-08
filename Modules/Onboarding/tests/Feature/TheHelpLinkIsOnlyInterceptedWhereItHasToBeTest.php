<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Onboarding\Internal\Http\Livewire\SetupWizard;
use Modules\Onboarding\Internal\Services\WizardProgressInitializer;

// The help link is a real anchor with target="_blank". It also carried
// wire:click.prevent unconditionally, which cancelled that anchor on every
// runtime — and only the Electron bundle has an opener that can do anything
// instead. On a phone and in a browser tab the reader pressed a dead link.

// Measured on an iPhone 12 mini: tapping a target="_blank" anchor inside the
// app's WebView brought Safari to the foreground and suspended the app, so the
// anchor needs no help there and the interception was pure loss.
beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'wizard-help-link',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    /** @var WizardProgressInitializer $initializer */
    $initializer = $this->app->make(WizardProgressInitializer::class);
    $initializer->initialize($this->user->id);
});

it('leaves the anchor alone where nothing would navigate the window away', function (): void {
    config()->set('nativephp-internal.running', false);

    $html = Livewire::test(SetupWizard::class)->html();

    expect($html)->toContain('wiz-help-link');
    expect(str_contains($html, 'wire:click.prevent="openHelp"'))->toBeFalse(
        'the anchor that would have opened the system browser was cancelled for a handler that cannot open anything here'
    );
});

it('intercepts only inside the shell that would navigate its own window', function (): void {
    config()->set('nativephp-internal.running', true);

    $html = Livewire::test(SetupWizard::class)->html();

    expect(str_contains($html, 'wire:click.prevent="openHelp"'))->toBeTrue(
        'Electron follows the anchor and leaves the wizard window on github.com'
    );
});

// Whichever way the click is handled, the address is the same one and it has
// been through the https and host allow-list.
it('offers the same allow-listed address either way', function (): void {
    foreach ([false, true] as $running) {
        config()->set('nativephp-internal.running', $running);

        expect(Livewire::test(SetupWizard::class)->html())->toContain('https://github.com/');
    }
});
