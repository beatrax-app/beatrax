<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Auth\Internal\Http\Livewire\RecoveryCodesDisplay;
use Modules\Auth\Public\Actions\SignupAction;
use Modules\Core\Models\User;

/**
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
    // Client-side: a WebView has no download manager for a StreamedResponse,
    // and a 419 on this screen destroys codes never shown again.
    $result = signupFirstUser();

    $rendered = Livewire::actingAs($result['user'])->test(RecoveryCodesDisplay::class)
        ->assertSee('beatrax-recovery-codes-alice.txt')
        ->assertSeeHtml('data-testid="recovery-codes-download"')
        ->html();

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

    // On to the setup wizard, not the dashboard: this screen sits between
    // signup and setup, so finishing it resumes onboarding.
    Livewire::actingAs($result['user'])->test(RecoveryCodesDisplay::class)
        ->set('confirmed', true)
        ->call('continueAfterSave')
        ->assertRedirect(route('setup'));

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
