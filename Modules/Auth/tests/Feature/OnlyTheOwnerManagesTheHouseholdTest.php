<?php

declare(strict_types=1);

use Livewire\Livewire;
use Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware;
use Modules\Auth\Internal\Http\Livewire\ManageUserPage;
use Modules\Auth\Internal\Http\Middleware\AppLockMiddleware;
use Modules\Auth\Internal\Http\Middleware\ForcePasswordChangeMiddleware;
use Modules\Auth\Public\Actions\AddUserAction;
use Modules\Auth\Public\Actions\RegenerateRecoveryCodesAction;
use Modules\Core\Models\User;
use Modules\Shell\Internal\Http\Livewire\SettingsPage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// Developer mode is self-settable from /settings by design, so it can never be
// the gate on the surfaces that reset another person's password or burn their
// recovery codes.
function householdOwner(): User
{
    return User::query()->create([
        'username' => 'household-owner',
        'password' => 'owner-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'base_currency' => 'EUR',
        'is_developer' => true,
    ]);
}

function householdPartner(): User
{
    return User::query()->create([
        'username' => 'household-partner',
        'password' => 'partner-password-12ch',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'base_currency' => 'EUR',
        'is_developer' => false,
    ]);
}

it('refuses a self-promoted partner the owners management page', function (): void {
    $owner = householdOwner();
    $partner = householdPartner();
    $this->actingAs($partner);

    // The promotion itself is allowed by design; what it must not unlock is
    // the surface that resets somebody else's password.
    Livewire::test(SettingsPage::class)->call('setDevMode', true);
    expect($partner->fresh()->is_developer)->toBeTrue();

    $this->get(route('auth.users.manage', ['username' => $owner->username]))->assertNotFound();
});

it('lets the owner open the partners management page', function (): void {
    $owner = householdOwner();
    $partner = householdPartner();
    $this->actingAs($owner);

    $this->get(route('auth.users.manage', ['username' => $partner->username]))->assertOk();
});

it('refuses a self-promoted partner the owners recovery codes', function (): void {
    $owner = householdOwner();
    $partner = householdPartner();
    $partner->update(['is_developer' => true]);

    app(RegenerateRecoveryCodesAction::class)($partner->fresh(), $owner->username);
})->throws(NotFoundHttpException::class);

it('refuses a self-promoted partner the add-user action', function (): void {
    householdOwner();
    $partner = householdPartner();
    $partner->update(['is_developer' => true]);

    app(AddUserAction::class)($partner->fresh(), 'a-third-person', 'third-password-12ch');
})->throws(NotFoundHttpException::class);

it('still lets the owner reset a partner password and mint their codes', function (): void {
    $owner = householdOwner();
    $partner = householdPartner();
    $this->actingAs($owner);

    Livewire::test(ManageUserPage::class, ['username' => $partner->username])
        ->set('newPartnerPassword', 'a-brand-new-password')
        ->call('setPartnerPassword')
        ->call('regenerateCodes')
        ->assertCount('regeneratedCodes', 10);

    expect($partner->fresh()->force_password_change_at_next_login)->toBeTrue();
});

it('lets a partner still mint their own codes', function (): void {
    householdOwner();
    $partner = householdPartner();

    expect(app(RegenerateRecoveryCodesAction::class)($partner, $partner->username))->toHaveCount(10);
});

it('carries the forced-password-change gate onto livewire updates', function (): void {
    // Livewire's update endpoint runs outside the `auth` route middleware
    // group, so a flagged session kept driving every component whose snapshot
    // it already held while every page redirected it away. AppLockMiddleware
    // is registered here for exactly that reason; this one was not.
    expect(app(PersistentMiddleware::class)->getPersistentMiddleware())
        ->toContain(ForcePasswordChangeMiddleware::class)
        ->toContain(AppLockMiddleware::class);
});
