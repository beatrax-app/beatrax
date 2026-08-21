<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Mobile\Internal\Http\Livewire\MobileLockScreen;

// The phone is where being locked out has no way around it: no CLI, no second
// window. Sign-out is the whole escape route, so the screen has to name it as
// one — and the control has to be the POST form the sign-out already is, since
// a plain link to the logout route does nothing.

/** @return list<string> the visible label of every control that submits to the logout route */
function mobileLockLogoutControlLabels(string $html): array
{
    $labels = [];

    preg_match_all('/<form\b[^>]*>.*?<\/form>/s', $html, $forms);

    foreach ($forms[0] as $form) {
        preg_match('/\baction="([^"]*)"/', $form, $action);
        preg_match('/\bmethod="([^"]*)"/', $form, $method);

        $target = html_entity_decode($action[1] ?? '', ENT_QUOTES);
        if ($target !== route('logout') || strtoupper($method[1] ?? '') !== 'POST') {
            continue;
        }

        preg_match_all('/<button\b[^>]*>(.*?)<\/button>/s', $form, $buttons);
        foreach ($buttons[1] as $inner) {
            $text = html_entity_decode(strip_tags($inner), ENT_QUOTES);
            $labels[] = trim((string) preg_replace('/\s+/', ' ', $text));
        }
    }

    return $labels;
}

function lockedOutPhoneUser(string $username): User
{
    /** @var User $user */
    $user = User::query()->create([
        'username' => $username,
        'password' => 'account-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    test()->actingAs($user);
    app(AppLockProvisioner::class)->enable((int) $user->id, '123456', 'account-password');
    test()->session([AppLockTestHarness::LOCKED_SESSION_KEY => true]);

    return $user;
}

it('offers the forgotten-code way back in as a control that signs out', function (): void {
    lockedOutPhoneUser('forgot-phone');

    $html = Livewire::test(MobileLockScreen::class)->html();

    expect(mobileLockLogoutControlLabels($html))
        ->toContain(Lang::get('mobile::lock.sign_out'))
        ->toContain(Lang::get('mobile::lock.forgot_pin'));
});

it('says on the lock screen itself that the way back in signs you out', function (): void {
    $copy = Lang::get('mobile::lock.forgot_pin');

    expect($copy)->toContain(Lang::get('mobile::lock.sign_out'))
        ->and($copy)->toContain('account password');
});

it('routes the forgotten-code control through the POST sign-out, never a bare link', function (): void {
    lockedOutPhoneUser('forgot-phone-post-only');

    $html = Livewire::test(MobileLockScreen::class)->html();

    expect($html)->not->toMatch('/<a\b[^>]*href="[^"]*'.preg_quote((string) parse_url(route('logout'), PHP_URL_PATH), '/').'"/')
        ->and($html)->toContain('beatraxSubmitPostForm');
});
