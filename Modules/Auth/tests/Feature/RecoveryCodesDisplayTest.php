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

// Was a 404, and the codes are still never re-shown — but signup leaves this
// page one history entry behind the setup wizard, whose nine steps share a URL,
// so on the Samsung the system back button on step one of first-run setup
// showed "404 · This page does not exist". A reader who has finished the
// ceremony is behind, not lost, and is sent on; a guest still meets the refusal.
it('sends a reader past the spent ceremony rather than showing an error', function (): void {
    $user = User::query()->create([
        'username' => 'alice',
        'password' => 'whatever-password',
        'period_start_day' => 1,
    ]);

    $this->actingAs($user)->get('/recovery-codes')->assertRedirect(route('setup'));
});

it('never lets a guest reach the page at all', function (): void {
    $response = $this->get('/recovery-codes');

    expect($response->getStatusCode())->not->toBe(200)
        ->and($response->headers->get('location'))->not->toBe(route('setup'));
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

    $this->actingAs($result['user'])->get('/recovery-codes')->assertRedirect(route('setup'));
});

it('does not redirect from Continue while the checkbox is unticked', function (): void {
    $result = signupFirstUser();

    Livewire::actingAs($result['user'])->test(RecoveryCodesDisplay::class)
        ->set('confirmed', false)
        ->call('continueAfterSave')
        ->assertNoRedirect();
});
