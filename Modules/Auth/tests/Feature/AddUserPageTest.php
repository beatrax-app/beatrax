<?php

declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Auth\Internal\Http\Livewire\AddUserPage;
use Modules\Auth\Models\UserRecoveryCode;
use Modules\Auth\Public\Actions\AddUserAction;
use Modules\Core\Models\User;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

function developerCaller(): User
{
    return User::query()->create([
        'username' => 'owner',
        'password' => 'owner-password-12chars',
        'period_start_day' => 1,
        'is_developer' => true,
    ]);
}

it('creates a partner flagged for a forced password change', function (): void {
    /** @var AddUserAction $addUser */
    $addUser = $this->app->make(AddUserAction::class);

    $partner = $addUser(developerCaller(), 'partner', 'partner-initial-pw-12');

    expect($partner)->toBeInstanceOf(User::class);
    expect($partner->is_developer)->toBeFalse();
    expect($partner->force_password_change_at_next_login)->toBeTrue();
});

it('lowercases the partner username before storage', function (): void {
    /** @var AddUserAction $addUser */
    $addUser = $this->app->make(AddUserAction::class);

    $partner = $addUser(developerCaller(), 'Bob', 'partner-initial-pw-12');

    expect($partner->username)->toBe('bob');
});

// The sheet is minted at the partner's own forced password change instead --
// see ThePartnerIsHandedTheirOwnRecoveryCodesTest -- because codes issued here
// are codes neither the owner nor the partner is ever shown.
it('provisions no recovery codes the partner could not be handed', function (): void {
    /** @var AddUserAction $addUser */
    $addUser = $this->app->make(AddUserAction::class);

    $partner = $addUser(developerCaller(), 'partner', 'partner-initial-pw-12');

    expect(UserRecoveryCode::query()->where('user_id', $partner->id)->get())->toHaveCount(0);
});

it('rejects a duplicate username with the locked copy', function (): void {
    $caller = developerCaller();

    /** @var AddUserAction $addUser */
    $addUser = $this->app->make(AddUserAction::class);

    $addUser($caller, 'partner', 'partner-initial-pw-12');

    try {
        $addUser($caller, 'partner', 'another-initial-pw-12');
        $this->fail('expected a ValidationException');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('username');
        expect($e->errors()['username'][0])
            ->toBe('That username is already in use on this device. Try another one.');
    }
});

it('rejects a password shorter than twelve characters', function (): void {
    /** @var AddUserAction $addUser */
    $addUser = $this->app->make(AddUserAction::class);

    try {
        $addUser(developerCaller(), 'partner', 'short');
        $this->fail('expected a ValidationException');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('password');
        expect($e->errors()['password'][0])->toBe('Use at least 12 characters.');
    }

    expect(User::query()->where('username', 'partner')->exists())->toBeFalse();
});

// The gate is the account created FIRST, not is_developer, so the fixture has
// to mint an owner before the caller: on its own the caller IS the owner.
it('throws a 404 for a caller who is not the account owner', function (): void {
    developerCaller();

    $nonDeveloper = User::query()->create([
        'username' => 'plainuser',
        'password' => 'plain-password-12ch',
        'period_start_day' => 1,
        'is_developer' => false,
    ]);

    /** @var AddUserAction $addUser */
    $addUser = $this->app->make(AddUserAction::class);

    expect(fn () => $addUser($nonDeveloper, 'partner', 'partner-initial-pw-12'))
        ->toThrow(NotFoundHttpException::class);
});

it('renders the add-user page for a developer', function (): void {
    $this->actingAs(developerCaller())->get('/settings/users/new')
        ->assertOk()
        ->assertSeeText('Add a user')
        ->assertSeeText('Create an account for someone else on this device. They will be asked to set their own password the first time they sign in.')
        ->assertSeeText('Set initial password');
});

it('returns 404 from the add-user route for a non-developer', function (): void {
    $nonDeveloper = User::query()->create([
        'username' => 'plainuser',
        'password' => 'plain-password-12ch',
        'period_start_day' => 1,
        'is_developer' => false,
    ]);

    $this->actingAs($nonDeveloper)->get('/settings/users/new')->assertNotFound();
});

it('does not expose the add-user page to an unauthenticated visitor', function (): void {
    $this->get('/settings/users/new')->assertRedirect('/login');
});

it('creates the partner and flashes the success copy on submit', function (): void {
    Livewire::actingAs(developerCaller())->test(AddUserPage::class)
        ->set('username', 'partner')
        ->set('initialPassword', 'partner-initial-pw-12')
        ->set('initialPasswordConfirmation', 'partner-initial-pw-12')
        ->call('submit')
        ->assertSet('flashMessage', 'User partner created. They will set their own password the first time they sign in.');

    $partner = User::query()->where('username', 'partner')->first();
    expect($partner)->not->toBeNull();
    expect($partner->force_password_change_at_next_login)->toBeTrue();
});

// The same shape as the signup screen's blank submit: an empty username used to
// raise an InvalidArgumentException past the page's ValidationException catch.
it('flashes an error for an empty username rather than raising', function (): void {
    Livewire::actingAs(developerCaller())->test(AddUserPage::class)
        ->set('username', '   ')
        ->set('initialPassword', 'partner-initial-pw-12')
        ->set('initialPasswordConfirmation', 'partner-initial-pw-12')
        ->call('submit')
        ->assertSet('flashMessage', 'Use up to 32 letters, digits, dots, dashes or underscores.');
});

it('flashes a mismatch error when the two passwords differ', function (): void {
    Livewire::actingAs(developerCaller())->test(AddUserPage::class)
        ->set('username', 'partner')
        ->set('initialPassword', 'partner-initial-pw-12')
        ->set('initialPasswordConfirmation', 'a-different-password')
        ->call('submit')
        ->assertSet('flashMessage', 'Passwords do not match.');

    expect(User::query()->where('username', 'partner')->exists())->toBeFalse();
});
