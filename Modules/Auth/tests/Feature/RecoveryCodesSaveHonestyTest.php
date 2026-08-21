<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Auth\Internal\Http\Livewire\RecoveryCodesDisplay;
use Modules\Core\Models\User;

uses(RefreshDatabase::class);

// The blob download this screen falls back to writes nothing inside a WebView,
// with no error, no console entry and no file — and the screen reported
// "Saved as beatrax-recovery-codes-<name>.txt" anyway. These codes are shown
// once and are the only way back into an account, so a save that lies is worse
// than one that refuses.
function recoveryCodesSaveUser(string $username): User
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

it('offers the share sheet when the mobile export endpoint is reachable', function (): void {
    $user = recoveryCodesSaveUser('recovery-native');

    $this->actingAs($user)->withSession([
        RecoveryCodesDisplay::SESSION_KEY => ['ABCD-EFGH-JKLM-NPQR-STUV'],
    ]);

    $html = Livewire::test(RecoveryCodesDisplay::class)->html();

    // The endpoint is the thing that knows whether the file was kept, so the
    // screen has to ask it rather than assume. It arrives JSON-encoded into the
    // attribute, so the slashes are escaped.
    expect($html)->toContain(str_replace('/', '\/', route('mobile.recovery-codes.export')));
})->skip(
    fn (): bool => ! app('router')->has('mobile.recovery-codes.export'),
    'the mobile export route is not registered in this composer root',
);

it('never reports a save it did not make', function (): void {
    $user = recoveryCodesSaveUser('recovery-honest');

    $this->actingAs($user)->withSession([
        RecoveryCodesDisplay::SESSION_KEY => ['ABCD-EFGH-JKLM-NPQR-STUV'],
    ]);

    $html = Livewire::test(RecoveryCodesDisplay::class)->html();

    // `saved` and `failed` both come from the answer, so a refusal cannot read
    // as a success. The blob path below it keeps its unconditional assignment
    // on purpose: a real browser download manager either writes the file or
    // raises, and that path is never reached on a phone.
    expect($html)->toContain('this.saved = result.saved === true;')
        ->and($html)->toContain('this.failed = result.saved !== true;');

    // The share sheet has to be tried first; reaching the blob fallback on a
    // phone is the bug.
    expect(strpos($html, 'result.saved === true'))
        ->toBeLessThan((int) strpos($html, 'URL.createObjectURL'));
});
