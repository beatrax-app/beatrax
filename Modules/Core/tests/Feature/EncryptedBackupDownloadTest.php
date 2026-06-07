<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Internal\Http\Livewire\EncryptedBackupDownload;
use Modules\Core\Models\User;

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'backup-fixture',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);
});

it('renders the encrypted-backup form on the SQLite build', function (): void {
    Livewire::test(EncryptedBackupDownload::class)
        ->assertOk()
        ->assertSee('Download encrypted backup');
});

it('rejects a passphrase shorter than 8 characters', function (): void {
    Livewire::test(EncryptedBackupDownload::class)
        ->set('passphrase', 'short')
        ->set('confirmPassphrase', 'short')
        ->call('download')
        ->assertSet('error', 'Use a passphrase of at least 8 characters.');
});

it('rejects mismatched passphrases', function (): void {
    Livewire::test(EncryptedBackupDownload::class)
        ->set('passphrase', 'a-good-passphrase')
        ->set('confirmPassphrase', 'a-different-one')
        ->call('download')
        ->assertSet('error', 'The two passphrases do not match.');
});
