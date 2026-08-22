<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Auth\Internal\Http\Livewire\RecoveryCodesDisplay;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;

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

it('asks the endpoint on a shell that drops WebView downloads', function (): void {
    $user = recoveryCodesSaveUser('recovery-native');

    $this->actingAs($user)->withSession([
        RecoveryCodesDisplay::SESSION_KEY => ['ABCD-EFGH-JKLM-NPQR-STUV'],
    ]);

    // Android, not iOS. The route is registered in every composer root, and the
    // Android shell registers no DownloadListener, so a blob <a download> there
    // is dropped without a word and the kept copy is all there is. The iOS shell
    // saves the download and shows a share sheet, so it is not sent here.
    $_SERVER['NATIVEPHP_PLATFORM'] = 'android';

    try {
        $html = Livewire::test(RecoveryCodesDisplay::class)->html();
    } finally {
        unset($_SERVER['NATIVEPHP_PLATFORM']);
    }

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

    // `saved` and `saveFailed` both come from the answer, so a refusal cannot
    // read as a success. The blob path below it keeps its unconditional
    // assignment on purpose: a real browser download manager either writes the
    // file or raises, and that path is never reached on a phone.
    expect($html)->toContain('this.saved = result.saved === true;')
        ->and($html)->toContain('this.saveFailed = result.saved !== true;');

    // The share sheet has to be tried first; reaching the blob fallback on a
    // phone is the bug.
    expect(strpos($html, 'result.saved === true'))
        ->toBeLessThan((int) strpos($html, 'URL.createObjectURL'));
});

it('tells a reader whose save failed that the save failed, not the copy', function (): void {
    $user = recoveryCodesSaveUser('recovery-vocabulary');

    $this->actingAs($user)->withSession([
        RecoveryCodesDisplay::SESSION_KEY => ['ABCD-EFGH-JKLM-NPQR-STUV'],
    ]);

    $_SERVER['NATIVEPHP_PLATFORM'] = 'android';

    try {
        $html = Livewire::test(RecoveryCodesDisplay::class)->html();
    } finally {
        unset($_SERVER['NATIVEPHP_PLATFORM']);
    }

    // Copy and Save had one flag between them, so a refused save said "Could
    // not copy" — and these codes are on screen exactly once, so a reader who
    // reaches for the clipboard instead of a pen ends up with neither.
    expect($html)->toContain('this.saveFailed = result.saved !== true;')
        ->and($html)->not->toContain('this.failed = result.saved !== true;');

    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($document);

    $onSaveFailure = $xpath->query('//p[@x-show="saveFailed"]')->item(0);
    $onCopyFailure = $xpath->query('//p[@x-show="failed"]')->item(0);

    expect($onSaveFailure)->toBeInstanceOf(DOMElement::class, 'a failed save must have a line of its own')
        ->and($onCopyFailure)->toBeInstanceOf(DOMElement::class, 'a failed copy keeps its own line');

    /** @var DOMElement $onSaveFailure */
    /** @var DOMElement $onCopyFailure */
    expect(trim($onSaveFailure->textContent))->toBe((string) Lang::get('auth::recovery_codes.save_failed'))
        ->and(trim($onCopyFailure->textContent))->toBe((string) Lang::get('auth::recovery_codes.copy_failed'))
        ->and(trim($onSaveFailure->textContent))->not->toBe(trim($onCopyFailure->textContent));
})->skip(
    fn (): bool => ! app('router')->has('mobile.recovery-codes.export'),
    'the mobile export route is not registered in this composer root',
);
