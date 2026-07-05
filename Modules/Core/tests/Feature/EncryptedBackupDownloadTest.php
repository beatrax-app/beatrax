<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Internal\Http\Livewire\EncryptedBackupDownload;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\UserDataPathService;

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

it('stages the plaintext snapshot in a private 0700 app dir, never sys_get_temp_dir', function (): void {
    // The plaintext-snapshot VACUUM INTO itself cannot be exercised under the
    // transactional test harness (SQLite refuses "VACUUM from within a
    // transaction" — the same reason this file never covered the happy path),
    // so this asserts the security-relevant staging decision that runs BEFORE
    // the VACUUM: the snapshot path is derived under the private app dir and
    // that dir is created without group/other access, so the brief plaintext
    // window can never sit in world-traversable /tmp.
    $stagingDir = UserDataPathService::appPath('tmp-backups');

    // Remove any dir a prior run left behind so the assertion below proves
    // THIS run's code recreated it (pre-fix, nothing ever creates it).
    if (is_dir($stagingDir)) {
        array_map('unlink', glob($stagingDir.'/*') ?: []);
        rmdir($stagingDir);
    }

    // Snapshot the shared temp dir so a leak shows up as a NEW entry.
    $tmpGlob = static fn (): array => glob(sys_get_temp_dir().'/beatrax-backup-*') ?: [];
    $before = $tmpGlob();

    // VACUUM will throw inside the test transaction and the component surfaces
    // that as an error — expected; the staging decision has already happened.
    Livewire::test(EncryptedBackupDownload::class)
        ->set('passphrase', 'a-good-passphrase')
        ->set('confirmPassphrase', 'a-good-passphrase')
        ->call('download');

    // The private staging dir was created, and is not group/other-accessible.
    expect(is_dir($stagingDir))->toBeTrue();
    expect(fileperms($stagingDir) & 0o077)->toBe(0);

    // No plaintext snapshot path was ever routed to world-traversable /tmp.
    expect(array_diff($tmpGlob(), $before))->toBe([]);

    // No plaintext snapshot is left behind in the staging dir either.
    expect(glob($stagingDir.'/*.sqlite') ?: [])->toBe([]);
});
