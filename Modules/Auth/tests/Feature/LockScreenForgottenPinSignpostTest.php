<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Livewire\Livewire;
use Modules\Auth\Internal\Http\Livewire\LockScreen;
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
    $label = Lang::get('auth::lock_screen.forgot_pin');

    expect($label)->toContain(Lang::get('auth::lock_screen.sign_out'))
        ->and(Lang::get('auth::help.forgot_pin'))->toContain('account password');
});

// The label used to carry all of it: three lines of prose under a pad whose
// only job is six digits. What the sign-out is worth is a question, so it went
// behind a mark the reader opens — and the label kept the one thing that has
// to be read before the control is tapped, which is that it signs you out.
it('keeps the explanation off the label and behind the mark', function (): void {
    $label = Lang::get('auth::lock_screen.forgot_pin');

    expect(mb_strlen($label))->toBeLessThan(60, 'The forgotten-code control is a label again only while it reads as one.')
        ->and($label)->not->toContain('recovery code')
        ->and($label)->not->toContain('account password');
});

// The line used to end "No data is lost." Three of the six paths that write a
// password stamp the recovery wrap stale, and after any of them the account
// password opens nothing: the reader is told to go and do the one thing that
// cannot work. The caveat is what makes the rest of the sentence true.
it('names the resets that leave nothing behind the PIN', function (): void {
    $copy = Lang::get('auth::help.forgot_pin');

    $stalePaths = [
        'Modules/Auth/Public/Actions/ResetPasswordAction.php',
        'Modules/Auth/Internal/Http/Livewire/ManageUserPage.php',
        'Modules/Auth/Internal/Console/ResetPasswordCommand.php',
    ];

    foreach ($stalePaths as $path) {
        expect((string) file_get_contents(base_path($path)))->toContain('markRecoveryWrapStale');
    }

    expect($copy)->toContain('recovery code')
        ->and($copy)->toContain('account owner')
        ->and($copy)->not->toContain('No data is lost');
});

// The question only occurs to a reader who has got the code wrong, and until
// then the mark is one more thing on a screen that is already full. The count
// it waits on is the pad's own failure meter, so a reload cannot hand back a
// screen that has forgotten what the reader just did.
it('offers the explanation only once the reader has actually got the PIN wrong', function (): void {
    lockedOutUser('forgot-desktop-progressive');

    $before = Livewire::test(LockScreen::class);

    expect($before->html())->not->toContain('help-tip')
        ->and($before->html())->not->toContain(Lang::get('auth::help.forgot_pin'));

    $after = $before->call('submit', '999999');

    expect($after->html())->toContain('help-tip')
        ->and($after->html())->toContain(Lang::get('auth::help.forgot_pin'));
});

it('still has the mark on a screen the reader has come back to, because the meter is what it reads', function (): void {
    lockedOutUser('forgot-desktop-reload');

    Livewire::test(LockScreen::class)->call('submit', '999999');

    $html = (string) $this->get(route('auth.lock'))->assertOk()->getContent();

    expect($html)->toContain('help-tip')
        ->and($html)->toContain(Lang::get('auth::help.forgot_pin'));
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
