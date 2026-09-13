<?php

declare(strict_types=1);

use Illuminate\Auth\AuthManager;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Auth\Internal\Http\Livewire\ManageUserPage;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\PatternScan;

// The app lock redirects to /lock and the forced password change redirects to
// /change-password, and each of those is the route the other refuses. Both of
// the paths that set the flag from outside the account also stale the recovery
// wrap, which is exactly what makes the next sign-in start locked.

const FORECLOSE_UNLOCK_PIN = '246810';

function forecloseUnlockOwner(): User
{
    /** @var User $owner */
    $owner = User::query()->create([
        'username' => 'foreclose-owner',
        'password' => bcrypt('foreclose-owner-pass'),
        'period_start_day' => 1,
        'is_developer' => true,
    ]);

    return $owner;
}

// A partner with the app-lock on, which is what turns an owner's reset into a
// session that starts locked: the recovery wrap no longer opens under the
// password the owner just chose.
function forecloseUnlockPartner(string $password): User
{
    /** @var User $partner */
    $partner = User::query()->create([
        'username' => 'foreclose-partner',
        'password' => bcrypt($password),
        'period_start_day' => 1,
        'is_developer' => false,
    ]);

    /** @var AppLockProvisioner $provisioner */
    $provisioner = app(AppLockProvisioner::class);
    $provisioner->enable($partner->id, FORECLOSE_UNLOCK_PIN, $password);

    return $partner;
}

// The guard is forgotten as well as the session: a test still holding the
// owner meets RedirectIfAuthenticated and never reaches the pipeline that
// primes the lock.
function forecloseUnlockSignIn(string $username, string $password): void
{
    test()->flushSession();
    app(AuthManager::class)->forgetGuards();

    test()->post('/login', ['username' => $username, 'password' => $password]);
}

it('leaves a partner the owner reset with a lock screen they can reach', function (): void {
    forecloseUnlockOwner();
    $partner = forecloseUnlockPartner('foreclose-partner-old');

    test()->actingAs(User::query()->where('username', 'foreclose-owner')->firstOrFail());

    Livewire::test(ManageUserPage::class, ['username' => 'foreclose-partner'])
        ->set('newPartnerPassword', 'foreclose-partner-new')
        ->call('setPartnerPassword');

    expect(DB::table('users')->where('id', $partner->id)->value('force_password_change_at_next_login'))
        ->toEqual(1, 'the owner reset must still force a password change');

    forecloseUnlockSignIn('foreclose-partner', 'foreclose-partner-new');

    // The stale recovery wrap is what starts this session locked; without it
    // the pair of gates never meet and the test proves nothing.
    expect(session(LockStateManager::SESSION_KEY))->toBeTrue('the session must start locked for this to be the deadlock');

    test()->get('/')->assertRedirect(route('auth.lock'));

    // The one that used to send them back to /change-password, which sent them
    // back here, with no route in the application outside that pair.
    test()->get(route('auth.lock'))->assertOk();
});

it('still sends a locked session away from the change-password page', function (): void {
    $user = forecloseUnlockPartner('foreclose-locked-pass');
    DB::table('users')->where('id', $user->id)->update(['force_password_change_at_next_login' => true]);

    test()->actingAs($user->fresh())
        ->withSession([LockStateManager::SESSION_KEY => true])
        ->get(route('auth.change-password'))
        ->assertRedirect(route('auth.lock'));
});

// Over the real endpoint, not Livewire::test(): the guard is registered as
// persistent middleware, which only the HTTP transport runs, so a component
// test passes whatever the exemption list says.
it('spends the PIN over the Livewire endpoint the forced change also guards', function (): void {
    $user = forecloseUnlockPartner('foreclose-pin-pass');
    DB::table('users')->where('id', $user->id)->update(['force_password_change_at_next_login' => true]);

    test()->actingAs($user->fresh())->withSession([LockStateManager::SESSION_KEY => true]);

    $page = test()->get(route('auth.lock'))->assertOk()->getContent();
    $matches = PatternScan::all('/wire:snapshot="([^"]*)"/', (string) $page);

    $snapshot = '';
    foreach ($matches[1] as $encoded) {
        $candidate = html_entity_decode($encoded, ENT_QUOTES);
        if (str_contains($candidate, '"name":"auth.lock-screen"')) {
            $snapshot = $candidate;
        }
    }

    expect($snapshot)->not->toBe('', 'the lock screen rendered no snapshot to drive');

    test()->withHeaders(['X-Livewire' => 'true'])->postJson(route('default-livewire.update'), [
        '_token' => csrf_token(),
        'components' => [[
            'snapshot' => $snapshot,
            'updates' => [],
            'calls' => [['path' => '', 'method' => 'submit', 'params' => [FORECLOSE_UNLOCK_PIN]]],
        ]],
    ])->assertOk();

    expect(session(LockStateManager::SESSION_KEY))->toBeFalse('a correct PIN must unlock a session flagged for a forced change');
});

// The unlock is not an escape: the flag is still set, so the first ordinary
// route after it sends the reader to the page the flag exists for.
it('sends the unlocked reader on to the change-password page', function (): void {
    $user = forecloseUnlockPartner('foreclose-onward-pass');
    DB::table('users')->where('id', $user->id)->update(['force_password_change_at_next_login' => true]);

    test()->actingAs($user->fresh())
        ->withSession([LockStateManager::SESSION_KEY => false])
        ->get('/')
        ->assertRedirect(route('auth.change-password'));
});

it('leaves the console escape hatch with a reachable lock screen too', function (): void {
    $user = forecloseUnlockPartner('foreclose-console-old');

    test()->artisan('beatrax:reset-password', ['username' => 'foreclose-partner'])
        ->expectsQuestion('New password', 'foreclose-console-new')
        ->expectsQuestion('Confirm new password', 'foreclose-console-new')
        ->assertSuccessful();

    forecloseUnlockSignIn('foreclose-partner', 'foreclose-console-new');

    expect(session(LockStateManager::SESSION_KEY))->toBeTrue('the console reset stales the wrap, so the session starts locked');

    test()->get(route('auth.lock'))->assertOk();

    expect(User::query()->where('id', $user->id)->value('force_password_change_at_next_login'))->toEqual(1);
});
