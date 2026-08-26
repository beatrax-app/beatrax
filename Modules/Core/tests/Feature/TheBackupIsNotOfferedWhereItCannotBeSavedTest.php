<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Http\Livewire\EncryptedBackupDownload;

// Driven on the SM-S928B: passphrase typed, "Download encrypted backup"
// tapped, and the Livewire POST ran for 583ms — VACUUM INTO, Argon2id,
// XChaCha20 — before returning a BinaryFileResponse with
// deleteFileAfterSend(). The Android WebView has no download listener, so the
// response went nowhere and the file it had just written was deleted behind
// it. Nothing appeared in Downloads, nothing in the app container, and the
// screen said nothing at all.
//
// MobilePlatform::Android->savesWebViewDownloads() has answered false all
// along, and Share.File is not registered in that shell either, so there is
// no second road to offer. The honest screen is the one that does not offer
// the button.

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'backup-platform',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);
});

afterEach(function (): void {
    unset($_SERVER['NATIVEPHP_PLATFORM']);
});

it('does not offer a download the shell will drop', function (): void {
    $_SERVER['NATIVEPHP_PLATFORM'] = 'android';

    Livewire::test(EncryptedBackupDownload::class)
        ->assertDontSeeHtml('id="backup-passphrase"')
        ->assertSee('cannot save');
});

it('offers it on a shell that does save what the WebView downloads', function (): void {
    $_SERVER['NATIVEPHP_PLATFORM'] = 'ios';

    Livewire::test(EncryptedBackupDownload::class)
        ->assertSeeHtml('id="backup-passphrase"');
});

it('offers it off a phone entirely', function (): void {
    Livewire::test(EncryptedBackupDownload::class)
        ->assertSeeHtml('id="backup-passphrase"');
});
