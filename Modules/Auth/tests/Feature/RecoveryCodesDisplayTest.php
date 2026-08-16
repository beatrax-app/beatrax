<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Auth\Internal\Http\Livewire\RecoveryCodesDisplay;
use Modules\Auth\Public\Actions\SignupAction;
use Modules\Core\Models\User;

/*
 * Feature coverage for the recovery-codes inline display ceremony: the
 * one-time render, the 404 when visited without a fresh signup, the
 * .txt stream download, the checkbox-gated Continue button, and the
 * session-key clearing on completion.
 */

/**
 * Signs up the first user and returns them, leaving the plaintext codes
 * stashed in the session as the ceremony expects.
 *
 * @return array{user: User, codesPlain: list<string>}
 */
function signupFirstUser(): array
{
    /** @var SignupAction $signup */
    $signup = app(SignupAction::class);

    return $signup('alice', 'a-long-password-12chars');
}

it('renders the ten codes after signup', function (): void {
    $result = signupFirstUser();

    $component = Livewire::actingAs($result['user'])->test(RecoveryCodesDisplay::class)
        ->assertOk()
        ->assertSeeText('Save these recovery codes')
        ->assertSeeText('Print these or save them somewhere safe. They will not be shown again.');

    foreach ($result['codesPlain'] as $code) {
        $component->assertSee($code, false);
    }
});

it('returns 404 when visited without a fresh signup', function (): void {
    $user = User::query()->create([
        'username' => 'alice',
        'password' => 'whatever-password',
        'period_start_day' => 1,
    ]);

    $this->actingAs($user)->get('/recovery-codes')->assertNotFound();
});

it('hands the browser everything it needs to save the .txt itself', function (): void {
    // The save is client-side: a WebView has no download manager to receive a
    // StreamedResponse, so the old wire:click did nothing on device — and a
    // Livewire round-trip here can 419 on an expired page, which on this
    // screen destroys codes that are never shown again.
    $result = signupFirstUser();

    $rendered = Livewire::actingAs($result['user'])->test(RecoveryCodesDisplay::class)
        ->assertSee('beatrax-recovery-codes-alice.txt')
        ->assertSeeHtml('data-testid="recovery-codes-download"')
        ->html();

    // The payload the button writes is the formatter's own output, so the
    // file keeps the exact ten lines the .txt has always carried.
    foreach ($result['codesPlain'] as $code) {
        expect($rendered)->toContain($code);
    }
});

it('disables Continue until the checkbox is ticked', function (): void {
    $result = signupFirstUser();

    Livewire::actingAs($result['user'])->test(RecoveryCodesDisplay::class)
        ->assertSet('confirmed', false)
        ->assertSee('aria-disabled="true"', false)
        ->set('confirmed', true)
        ->assertSee('aria-disabled="false"', false);
});

it('completes the ceremony and clears the session key', function (): void {
    $result = signupFirstUser();

    Livewire::actingAs($result['user'])->test(RecoveryCodesDisplay::class)
        ->set('confirmed', true)
        ->call('continueAfterSave')
        ->assertRedirect(route('dashboard'));

    expect(session('auth.signup.recovery_codes_plain'))->toBeNull();

    $this->actingAs($result['user'])->get('/recovery-codes')->assertNotFound();
});

it('does not redirect from Continue while the checkbox is unticked', function (): void {
    $result = signupFirstUser();

    Livewire::actingAs($result['user'])->test(RecoveryCodesDisplay::class)
        ->set('confirmed', false)
        ->call('continueAfterSave')
        ->assertNoRedirect();
});
