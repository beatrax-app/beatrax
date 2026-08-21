<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Auth\Internal\Http\Livewire\RecoveryCodesDisplay;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;

// "Saved as beatrax-recovery-codes-<name>.txt" was printed on a phone for a
// file written into the app's private container: unreachable in Files, and
// destroyed by the reinstall recovery codes exist to survive. The bytes were
// real; the outcome the reader took from the sentence was not.

function recoveryCodesReaderUser(string $username): User
{
    /** @var User $user */
    $user = User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    return $user;
}

/**
 * @param  callable(): string  $render
 */
function recoveryCodesHtmlOnPhone(callable $render): string
{
    $_SERVER['NATIVEPHP_PLATFORM'] = 'ios';

    try {
        return $render();
    } finally {
        unset($_SERVER['NATIVEPHP_PLATFORM']);
    }
}

it('does not promise a filename on a runtime where the reader cannot open one', function (): void {
    $user = recoveryCodesReaderUser('recovery-phone');

    $this->actingAs($user)->withSession([
        RecoveryCodesDisplay::SESSION_KEY => ['ABCD-EFGH-JKLM-NPQR-STUV'],
    ]);

    $html = recoveryCodesHtmlOnPhone(
        fn (): string => (string) Livewire::test(RecoveryCodesDisplay::class)->html(),
    );

    // The filename line is gated behind the absence of the phone export, and
    // the phone gets a sentence that says what actually happened instead.
    expect($html)->toContain('x-show="saved && ! exportUrl"')
        ->and($html)->toContain(Lang::get('auth::recovery_codes.saved_native'));
});

it('keeps the browser download, and its filename, off the phone path', function (): void {
    $user = recoveryCodesReaderUser('recovery-desktop');

    $this->actingAs($user)->withSession([
        RecoveryCodesDisplay::SESSION_KEY => ['ABCD-EFGH-JKLM-NPQR-STUV'],
    ]);

    $html = (string) Livewire::test(RecoveryCodesDisplay::class)->html();

    // The export route is registered in every composer root, so a desktop that
    // gated on the route alone was sent through an endpoint that refuses
    // off-device and reported a failed save for a download it never tried.
    expect($html)->toContain('exportUrl: null')
        ->and($html)->toContain('Saved as beatrax-recovery-codes-recovery-desktop.txt');
});

it('reserves the system bars around the screen the codes are shown on once', function (): void {
    $user = recoveryCodesReaderUser('recovery-insets');

    $this->actingAs($user)->withSession([
        RecoveryCodesDisplay::SESSION_KEY => ['ABCD-EFGH-JKLM-NPQR-STUV'],
    ]);

    $html = (string) Livewire::test(RecoveryCodesDisplay::class)->html();

    // --safe-*, not env(safe-area-inset-*): the Android shell leaves env() at
    // zero and injects --inset-* onto :root, which --safe-* reads through max().
    expect($html)->toContain('var(--safe-bottom)')
        ->and($html)->toContain('var(--safe-top)');
});
