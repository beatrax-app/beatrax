<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Public\Exceptions\StrandedEncryptionEpochException;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Core\Public\Services\UserDataPathService;

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'stranded-keyring-user',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    $this->keyringPath = UserDataPathService::appPath('sync/gdk/'.$this->user->id.'.enc');
    @mkdir(dirname($this->keyringPath), 0o700, true);
});

afterEach(function (): void {
    /** @var string $keyringPath */
    $keyringPath = $this->keyringPath;
    @rmdir($keyringPath);
    @unlink($keyringPath);
    array_map('unlink', (array) glob($keyringPath.'.*.tmp'));
});

// The commit-then-finalize window: `current_epoch` is written inside the SQL
// transaction and the keyring file is renamed into place after it commits. A
// directory sitting on the keyring path is a rename that cannot succeed, which
// is the same ending as a full disk or a read-only store.
function strandTheKeyringFinalize(string $keyringPath): void
{
    mkdir($keyringPath, 0o700, true);
}

it('reports a keyring left un-finalized after the epoch committed', function (): void {
    /** @var string $keyringPath */
    $keyringPath = $this->keyringPath;
    strandTheKeyringFinalize($keyringPath);

    /** @var Session $session */
    $session = $this->app->make(Session::class);
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));

    /** @var EncryptionMigrationService $migration */
    $migration = $this->app->make(EncryptionMigrationService::class);

    expect(fn () => $migration->migrate($this->user, $session))
        ->toThrow(StrandedEncryptionEpochException::class, 'Keyring finalize failed after commit');

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $state = $db->connection()->table('sync_encryption_state')->where('user_id', $this->user->id)->first();
    expect($state?->current_epoch)->not->toBeNull('The epoch is committed by then; that is what makes this state stranded.');

    // The staged key file is the only copy of that epoch's key, so it is kept
    // rather than unlinked.
    expect((array) glob($keyringPath.'.*.tmp'))->not->toBe([]);
});

// Re-entry does NOT finalize the staged file: the stage lives on an
// EncryptionMigrationSupport the container binds fresh per resolution, so
// nothing in a later request holds the handle the rename needs. The refusal is
// the honest answer, and the sentence the first failure printed has to be the
// same one — an operator told to re-run this would re-run it forever.
it('refuses every later visit until the keyring file is put back by hand', function (): void {
    /** @var string $keyringPath */
    $keyringPath = $this->keyringPath;
    strandTheKeyringFinalize($keyringPath);

    /** @var Session $session */
    $session = $this->app->make(Session::class);
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));

    /** @var EncryptionMigrationService $migration */
    $migration = $this->app->make(EncryptionMigrationService::class);

    expect(fn () => $migration->migrate($this->user, $session))->toThrow(StrandedEncryptionEpochException::class);

    // The obstruction is gone: a reconciliation that existed would take it now.
    rmdir($keyringPath);

    expect(fn () => $migration->migrate($this->user, $session))
        ->toThrow(StrandedEncryptionEpochException::class, 'the GDK keyring holds no key');

    expect(file_exists($keyringPath))->toBeFalse('Nothing on the re-entry path renames the staged file into place.');
});
