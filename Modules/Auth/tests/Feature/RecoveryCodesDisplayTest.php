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
        ->assertSeeText('Print these or save them somewhere safe. They will not be shown again — only regenerated.');

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

it('streams the .txt download with the right filename and ten lines', function (): void {
    $result = signupFirstUser();

    $response = Livewire::actingAs($result['user'])->test(RecoveryCodesDisplay::class)
        ->call('download')
        ->assertSet('downloadShown', true)
        ->effects['download'] ?? null;

    $streamed = Livewire::actingAs($result['user'])->test(RecoveryCodesDisplay::class)
        ->call('download');

    $download = $streamed->effects['download'] ?? null;
    expect($download)->not->toBeNull();

    $disposition = $download['headers']['Content-Disposition'] ?? '';
    expect($disposition)->toContain('diederik-recovery-codes-alice.txt');

    $body = base64_decode($download['content'], true);
    expect($body)->toBeString();
    expect(explode("\n", (string) $body))->toHaveCount(10);
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
