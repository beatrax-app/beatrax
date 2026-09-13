<?php

declare(strict_types=1);

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Auth\Internal\Http\Livewire\AddUserPage;
use Modules\Auth\Public\Actions\AddUserAction;
use Modules\Core\Models\User;

// An account is a credential too, and this one is handed out with its password
// chosen by whoever asked. It survives the owner changing their own password,
// which is the property that made the recovery sheet a takeover rather than an
// unwanted write — and here the reader it belongs to does not exist to notice.

const MINTED_ACCOUNT_OWNER_PASSWORD = 'minted-owner-password-12';

function mintedAccountOwner(): User
{
    /** @var User $owner */
    $owner = User::query()->create([
        'username' => 'minting-owner',
        'password' => MINTED_ACCOUNT_OWNER_PASSWORD,
        'period_start_day' => 1,
        'is_developer' => true,
    ]);

    return $owner;
}

it('creates no account where the owner types no password of their own', function (): void {
    $owner = mintedAccountOwner();

    /** @var AddUserAction $addUser */
    $addUser = $this->app->make(AddUserAction::class);

    try {
        $addUser($owner, 'minted-partner', 'chosen-by-caller-12', '');
    } catch (ValidationException) {
        // The refusal is the point; what it left behind is the assertion.
    }

    expect(User::query()->where('username', 'minted-partner')->exists())->toBeFalse(
        'a whole account was created, with a password the caller chose, on owner authority alone',
    );
});

it('creates no account where the typed password is not the owner\'s', function (): void {
    $owner = mintedAccountOwner();

    /** @var AddUserAction $addUser */
    $addUser = $this->app->make(AddUserAction::class);

    expect(static fn () => $addUser($owner, 'minted-partner', 'chosen-by-caller-12', 'not-the-owners-password'))
        ->toThrow(ValidationException::class);

    expect(User::query()->where('username', 'minted-partner')->exists())->toBeFalse();
});

it('creates the account once the owner proves they are the owner', function (): void {
    $owner = mintedAccountOwner();

    /** @var AddUserAction $addUser */
    $addUser = $this->app->make(AddUserAction::class);

    $partner = $addUser($owner, 'minted-partner', 'chosen-by-caller-12', MINTED_ACCOUNT_OWNER_PASSWORD);

    /** @var Hasher $hasher */
    $hasher = $this->app->make(Hasher::class);

    expect($partner->username)->toBe('minted-partner')
        ->and($partner->force_password_change_at_next_login)->toBeTrue()
        ->and($hasher->check('chosen-by-caller-12', $partner->password))->toBeTrue();
});

it('mints nothing from the page where the owner box is empty, and says so', function (): void {
    Livewire::actingAs(mintedAccountOwner())->test(AddUserPage::class)
        ->set('username', 'page-minted-partner')
        ->set('initialPassword', 'chosen-by-caller-12')
        ->set('initialPasswordConfirmation', 'chosen-by-caller-12')
        ->call('submit')
        ->assertSet('flashMessage', 'Enter your account password.');

    expect(User::query()->where('username', 'page-minted-partner')->exists())->toBeFalse(
        'the page minted an account for a caller that proved nothing',
    );
});

it('mints from the page, and clears the box, once the owner proves it', function (): void {
    $component = Livewire::actingAs(mintedAccountOwner())->test(AddUserPage::class)
        ->set('username', 'page-minted-partner')
        ->set('initialPassword', 'chosen-by-caller-12')
        ->set('initialPasswordConfirmation', 'chosen-by-caller-12')
        ->set('ownerPassword', MINTED_ACCOUNT_OWNER_PASSWORD)
        ->call('submit');

    expect(User::query()->where('username', 'page-minted-partner')->exists())->toBeTrue();
    expect($component->get('ownerPassword'))->toBe('');
});
