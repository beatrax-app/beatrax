<?php

declare(strict_types=1);

use Illuminate\Auth\AuthManager;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Auth\Internal\Http\Livewire\ChangePasswordPage;
use Modules\Auth\Internal\Http\Livewire\ManageUserPage;
use Modules\Auth\Internal\Recovery\RecoveryCodeMinter;
use Modules\Auth\Public\Actions\ResetPasswordAction;
use Modules\Core\Models\User;

// Deleting the `sessions` rows is not severing a session: the remember cookie
// re-authenticates into a brand-new one, so the account stays in the hands the
// password change was answering.

function seversUser(string $username, string $password, bool $developer = false): User
{
    /** @var User $user */
    $user = User::query()->create([
        'username' => $username,
        'password' => bcrypt($password),
        'period_start_day' => 1,
        'is_developer' => $developer,
    ]);

    return $user;
}

/**
 * @return array{name: string, value: string}
 */
function seversRememberCookie(string $username, string $password): array
{
    /** @var AuthManager $auth */
    $auth = app(AuthManager::class);

    /** @var SessionGuard $guard */
    $guard = $auth->guard();
    $name = $guard->getRecallerName();

    $response = test()->post('/login', [
        'username' => $username,
        'password' => $password,
        'remember' => 'on',
    ]);

    $cookie = $response->getCookie($name);
    expect($cookie)->not->toBeNull('the login response issued no remember cookie');

    return ['name' => $name, 'value' => (string) $cookie?->getValue()];
}

function seversFreshClient(): void
{
    test()->flushSession();
    app(AuthManager::class)->forgetGuards();
}

function seversRememberToken(int $userId): ?string
{
    $token = DB::table('users')->where('id', $userId)->value('remember_token');

    return is_string($token) ? $token : null;
}

function seversSeedSession(int $userId, string $id): void
{
    DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => $userId,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'seeded',
        'payload' => base64_encode(serialize([])),
        'last_activity' => time(),
    ]);
}

it('stops a captured remember cookie authenticating after a recovery-code reset', function (): void {
    $user = seversUser('severs-alice', 'severs-password-1');

    /** @var RecoveryCodeMinter $minter */
    $minter = app(RecoveryCodeMinter::class);
    $codes = $minter->issueFor($user->id);

    $cookie = seversRememberCookie('severs-alice', 'severs-password-1');

    /** @var ResetPasswordAction $reset */
    $reset = app(ResetPasswordAction::class);
    $reset('severs-alice', $codes[0], 'severs-brand-new-pw');

    seversFreshClient();

    test()->withCookie($cookie['name'], $cookie['value'])
        ->get(route('dashboard'))
        ->assertRedirect(route('login'));
});

it('rotates the remember token on a recovery-code reset', function (): void {
    $user = seversUser('severs-bob', 'severs-password-1');

    /** @var RecoveryCodeMinter $minter */
    $minter = app(RecoveryCodeMinter::class);
    $codes = $minter->issueFor($user->id);

    seversRememberCookie('severs-bob', 'severs-password-1');
    $before = seversRememberToken($user->id);
    expect($before)->toBeString();

    /** @var ResetPasswordAction $reset */
    $reset = app(ResetPasswordAction::class);
    $reset('severs-bob', $codes[0], 'severs-brand-new-pw');

    expect(seversRememberToken($user->id))->not->toBe($before);
});

it('stops a captured remember cookie authenticating after an in-app password change', function (): void {
    $user = seversUser('severs-carol', 'severs-password-1');

    $cookie = seversRememberCookie('severs-carol', 'severs-password-1');
    $before = seversRememberToken($user->id);

    Livewire::actingAs($user)->test(ChangePasswordPage::class)
        ->set('currentPassword', 'severs-password-1')
        ->set('newPassword', 'severs-brand-new-pw')
        ->set('newPasswordConfirmation', 'severs-brand-new-pw')
        ->call('submit');

    expect(seversRememberToken($user->id))->not->toBe($before);

    seversFreshClient();

    test()->withCookie($cookie['name'], $cookie['value'])
        ->get(route('dashboard'))
        ->assertRedirect(route('login'));
});

it('severs every session and the remember token from the reset-password command', function (): void {
    /** @var Hasher $hasher */
    $hasher = app(Hasher::class);

    $user = seversUser('severs-dave', 'severs-password-1');
    DB::table('users')->where('id', $user->id)->update(['remember_token' => 'severs-stale-token']);
    seversSeedSession($user->id, 'severs-cli-session-a');
    seversSeedSession($user->id, 'severs-cli-session-b');

    test()->artisan('beatrax:reset-password', ['username' => 'severs-dave'])
        ->expectsQuestion('New password', 'severs-brand-new-pw')
        ->expectsQuestion('Confirm new password', 'severs-brand-new-pw')
        ->assertSuccessful();

    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);
    expect(seversRememberToken($user->id))->not->toBe('severs-stale-token');
    expect($hasher->check('severs-brand-new-pw', (string) $user->fresh()?->password))->toBeTrue();
});

it('severs the partner sessions and remember token when the owner sets their password', function (): void {
    $owner = seversUser('severs-owner', 'severs-password-1', developer: true);
    $partner = seversUser('severs-partner', 'severs-password-1');

    DB::table('users')->where('id', $partner->id)->update(['remember_token' => 'severs-partner-token']);
    seversSeedSession($partner->id, 'severs-partner-session-a');
    seversSeedSession($partner->id, 'severs-partner-session-b');
    seversSeedSession($owner->id, 'severs-owner-session');

    Livewire::actingAs($owner)->test(ManageUserPage::class, ['username' => 'severs-partner'])
        ->set('newPartnerPassword', 'severs-brand-new-pw')
        ->call('setPartnerPassword');

    expect(DB::table('sessions')->where('user_id', $partner->id)->count())->toBe(0);
    expect(seversRememberToken($partner->id))->not->toBe('severs-partner-token');

    // The owner is not the account whose password moved; their own sessions and
    // recaller must be left standing.
    expect(DB::table('sessions')->where('user_id', $owner->id)->count())->toBe(1);
});
