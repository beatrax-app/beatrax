<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Onboarding\Internal\Http\Livewire\Steps\ConnectEmailStep;

/*
 * Acceptance coverage for WIZ-04 — Email step embeds the existing
 * OAuthClientWizardModal without duplicating the OAuth dance.
 *
 * Three behaviours under test:
 *
 *  1. The rendered HTML mounts the existing
 *     <livewire:email-scan.oauth-client-wizard-modal /> instance.
 *     The wizard email step is a thin event router — it does NOT
 *     reimplement the modal.
 *
 *  2. Calling `authorizeProvider('gmail')` dispatches
 *     `oauth-client-wizard:open` with `provider: 'gmail'` so the
 *     existing modal's #[On(...)] listener can open in the Gmail
 *     variant. The method's name is `authorizeProvider` to
 *     disambiguate from Livewire\Component::authorize() (which
 *     conflicts at the Larastan level if the step reuses
 *     `authorize`).
 *
 *  3. Receiving `oauth-client-wizard:saved` from the modal
 *     dispatches `wizard.step.completed` so the SetupWizard parent
 *     marks the email row `done` and advances.
 */

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'email-step',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);
});

it('mounts the OAuth client wizard modal inline (no duplicated dance)', function (): void {
    // Livewire compiles <livewire:email-scan.oauth-client-wizard-modal />
    // into a nested Livewire mount; the rendered HTML carries the
    // child component name on a `wire:name="..."` attribute. That is
    // the reliable signal that the modal is mounted in place rather
    // than duplicated in the step's own markup.
    Livewire::test(ConnectEmailStep::class)
        ->assertSeeHtml('wire:name="email-scan.oauth-client-wizard-modal"')
        ->assertSee('Authorize with Gmail')
        ->assertSee('Authorize with Outlook');
});

it('dispatches oauth-client-wizard:open with the provider on Gmail authorize', function (): void {
    Livewire::test(ConnectEmailStep::class)
        ->call('authorizeProvider', 'gmail')
        ->assertDispatched('oauth-client-wizard:open', provider: 'gmail');
});

it('dispatches oauth-client-wizard:open with the provider on Microsoft authorize', function (): void {
    Livewire::test(ConnectEmailStep::class)
        ->call('authorizeProvider', 'microsoft')
        ->assertDispatched('oauth-client-wizard:open', provider: 'microsoft');
});

it('dispatches wizard.step.completed when the modal saves credentials', function (): void {
    Livewire::test(ConnectEmailStep::class)
        ->dispatch('oauth-client-wizard:saved')
        ->assertDispatched('wizard.step.completed');
});

it('dispatches wizard.step.skipped when Skip is clicked', function (): void {
    Livewire::test(ConnectEmailStep::class)
        ->call('skip')
        ->assertDispatched('wizard.step.skipped');
});

it('ignores unknown provider strings (closed allow-list)', function (): void {
    Livewire::test(ConnectEmailStep::class)
        ->call('authorizeProvider', 'yahoo')
        ->assertNotDispatched('oauth-client-wizard:open');
});
