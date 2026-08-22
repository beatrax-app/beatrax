<?php

declare(strict_types=1);

use Illuminate\Contracts\Hashing\Hasher;
use Modules\Auth\Internal\Lock\AppLockCredentialRejections;
use Modules\Core\Public\Support\Lang;

// Six actions on the app-lock screen ask these three questions. The answers are
// pinned here rather than through one of the six, because the defect this class
// was extracted from was a vocabulary spread wide enough that an edit could only
// half-change it — and the half left behind is the one nobody re-reads.

function appLockRejections(): AppLockCredentialRejections
{
    return new AppLockCredentialRejections(app(Hasher::class));
}

it('names the empty PIN box rather than calling it wrong', function (): void {
    expect(appLockRejections()->pinRequired(''))
        ->toBe(Lang::get('auth::app_lock.error_pin_required'))
        ->and(appLockRejections()->pinRequired('426900'))->toBeNull();
});

// Six is the floor, so six passes and five does not. A rule stated as "at least
// six" is one an off-by-one rewrites into "more than six" without any test
// noticing, which locks a reader out of a PIN the screen told them to choose.
it('accepts a PIN of exactly the minimum length and refuses one digit short', function (): void {
    expect(appLockRejections()->newPin('426900', '426900'))->toBeNull()
        ->and(appLockRejections()->newPin('42690', '42690'))
        ->toBe(Lang::get('auth::app_lock.error_pin_too_short'));
});

// Length is asked before agreement: two short PINs that match are still too
// short, and reporting them as a mismatch sends the reader to retype rather
// than to lengthen.
it('reports a short pair as short and a long pair as mismatched', function (): void {
    expect(appLockRejections()->newPin('4269', '4269'))
        ->toBe(Lang::get('auth::app_lock.error_pin_too_short'))
        ->and(appLockRejections()->newPin('426900', '426901'))
        ->toBe(Lang::get('auth::app_lock.error_pin_mismatch'));
});

// The finding this round: a blank password read back as "incorrect" sends the
// reader to a password manager to check something they never typed. It was
// seven separate sites before the questions had one owner.
it('separates an empty password box from a password that is wrong', function (): void {
    $hash = app(Hasher::class)->make('a-genuinely-long-password');

    expect(appLockRejections()->accountPassword('', $hash))
        ->toBe(Lang::get('auth::app_lock.error_account_password_required'))
        ->and(appLockRejections()->accountPassword('not-the-password', $hash))
        ->toBe(Lang::get('auth::app_lock.error_account_password'))
        ->and(appLockRejections()->accountPassword('a-genuinely-long-password', $hash))
        ->toBeNull();
});

// Three distinct sentences, not one reused for two questions: the screen's whole
// job here is telling the reader which of the two things is wrong.
it('gives each rejection its own sentence', function (): void {
    $hash = app(Hasher::class)->make('a-genuinely-long-password');

    $sentences = [
        appLockRejections()->pinRequired(''),
        appLockRejections()->newPin('4269', '4269'),
        appLockRejections()->newPin('426900', '426901'),
        appLockRejections()->accountPassword('', $hash),
        appLockRejections()->accountPassword('wrong', $hash),
    ];

    expect($sentences)->not->toContain(null)
        ->and(array_unique($sentences))->toHaveCount(5);
});
