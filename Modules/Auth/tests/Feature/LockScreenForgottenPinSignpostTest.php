<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Auth\Public\Actions\LoginAction;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\PatternScan;

// Found by being locked out on a real device: a keypad and a rose "Sign out"
// were the whole screen, so a reader who forgot the code had no way of knowing
// a recovery existed. Seeing the copy on the screen is not the proof — the
// control has to submit to the route the copy promises.

/** @return list<string> the visible label of every control that submits to the logout route */
function authLockLogoutControlLabels(string $html): array
{
    $labels = [];

    $forms = PatternScan::all('/<form\b[^>]*>.*?<\/form>/s', $html);

    foreach ($forms[0] as $form) {
        $action = PatternScan::first('/\baction="([^"]*)"/', $form);
        $method = PatternScan::first('/\bmethod="([^"]*)"/', $form);

        $target = html_entity_decode($action[1] ?? '', ENT_QUOTES);
        if ($target !== route('logout') || strtoupper($method[1] ?? '') !== 'POST') {
            continue;
        }

        $buttons = PatternScan::all('/<button\b[^>]*>(.*?)<\/button>/s', $form);
        foreach ($buttons[1] as $inner) {
            $text = html_entity_decode(strip_tags($inner), ENT_QUOTES);
            $labels[] = trim((string) preg_replace('/\s+/', ' ', $text));
        }
    }

    return $labels;
}

function lockedOutUser(string $username): User
{
    /** @var User $user */
    $user = User::query()->create([
        'username' => $username,
        'password' => 'account-password',
        'period_start_day' => 1,
    ]);

    test()->actingAs($user);
    app(AppLockProvisioner::class)->enable((int) $user->id, '123456', 'account-password');
    test()->session([LockStateManager::SESSION_KEY => true]);

    return $user;
}

it('offers the forgotten-code way back in as a control that signs out', function (): void {
    lockedOutUser('forgot-desktop');

    $html = (string) $this->get(route('auth.lock'))->assertOk()->getContent();

    expect(authLockLogoutControlLabels($html))
        ->toContain(Lang::get('auth::lock_screen.sign_out'))
        ->toContain(Lang::get('auth::lock_screen.forgot_pin'));
});

it('says on the lock screen itself that the way back in signs you out', function (): void {
    $copy = Lang::get('auth::lock_screen.forgot_pin');

    expect($copy)->toContain(Lang::get('auth::lock_screen.sign_out'))
        ->and($copy)->toContain('account password');
});

it('lands the reader on login, where the account password unlocks without the code', function (): void {
    $user = lockedOutUser('forgot-desktop-round-trip');

    $this->post(route('logout'))->assertRedirect(route('login'));

    /** @var LockStateManager $lockState */
    $lockState = app(LockStateManager::class);
    /** @var Session $session */
    $session = app(Session::class);

    expect(app(LoginAction::class)($user->username, 'account-password', false))->toBeTrue()
        ->and($lockState->isLocked($session))->toBeFalse();
});
