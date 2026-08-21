<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Auth\Internal\Http\Livewire\SignupPage;
use Modules\Core\Models\User;

// Two device findings on one screen. A rejected submit emptied both password
// boxes while the checklist above them still ticked "At least 12 characters"
// and "Both passwords match", with "Use at least 12 characters." printed
// underneath the ticks; and the username error rendered ~330px below the
// username field, past both password boxes, with no aria-invalid to connect
// them.

it('leaves both password boxes as typed when the username is the thing that was wrong', function (): void {
    Livewire::test(SignupPage::class)
        ->set('username', 'ios@beatrax.test')
        ->set('password', 'a-long-password-12chars')
        ->set('passwordConfirmation', 'a-long-password-12chars')
        ->call('submit')
        ->assertNoRedirect()
        ->assertHasErrors(['username'])
        ->assertSet('password', 'a-long-password-12chars')
        ->assertSet('passwordConfirmation', 'a-long-password-12chars');

    expect(User::query()->count())->toBe(0);
});

it('puts the username error under the username field, above the password boxes', function (): void {
    $html = (string) Livewire::test(SignupPage::class)
        ->set('username', 'ios@beatrax.test')
        ->set('password', 'a-long-password-12chars')
        ->set('passwordConfirmation', 'a-long-password-12chars')
        ->call('submit')
        ->html();

    expect($html)->toContain('id="username-error"')
        ->and($html)->toContain('aria-describedby="username-hint username-error"')
        ->and($html)->toContain('aria-invalid="true"');

    // Position, not just presence: the defect was a message that rendered
    // correctly and 330px away from the field it described.
    expect(strpos($html, 'id="username-error"'))
        ->toBeLessThan((int) strpos($html, 'id="password"'));
});

it('marks the password box itself when the password is too short', function (): void {
    $html = (string) Livewire::test(SignupPage::class)
        ->set('username', 'alice')
        ->set('password', 'short')
        ->set('passwordConfirmation', 'short')
        ->call('submit')
        ->html();

    expect($html)->toContain('id="password-error"');

    expect(strpos($html, 'id="password-error"'))
        ->toBeLessThan((int) strpos($html, 'id="password-confirmation"'));
});

it('reads the requirement checklist off the same binding the server validates', function (): void {
    $html = (string) Livewire::test(SignupPage::class)->html();

    // A private Alpine mirror fed only by input events cannot see a value the
    // server changed, which is how two green ticks survived two emptied boxes.
    // The shared checklist is handed the two wire properties by name, and
    // reads them off $wire itself.
    expect($html)->toContain("passwordStrength(12, 'password', 'passwordConfirmation')")
        ->and($html)->not->toContain('$event.target.value');
});
