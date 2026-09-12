<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Modules\Auth\Public\Actions\DeleteAccountAction;
use Modules\Core\Models\User;
use Modules\Core\Public\Scheduling\MobileBackgroundSchedule;
use Modules\Core\Public\Services\UserDataPathService;
use Psr\Log\LoggerInterface;

// The three unlinks that end an account used to run inside the deletion
// transaction, so any rollback past them put the rows back over keys that were
// already gone: an account nobody can read, no tombstone, nothing raised. The
// unlink is past the commit now, and what commits in its place is the debt.

/** @return list<string> the app-relative key material an account owns */
function keyDebtKeyMaterialPaths(int $userId): array
{
    return [
        'sync/identity/'.$userId.'.enc',
        'sync/gdk/'.$userId.'.enc',
        'secrets/open-banking/'.$userId.'.json',
    ];
}

function keyDebtUser(string $username): User
{
    /** @var User $user */
    $user = User::query()->create([
        'username' => $username,
        'password' => $username.'-password-12',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'is_developer' => true,
    ]);

    return $user;
}

function keyDebtWriteKeyMaterial(int $userId): void
{
    $paths = app(UserDataPathService::class);
    $files = app(Filesystem::class);

    foreach (keyDebtKeyMaterialPaths($userId) as $relative) {
        $path = $paths->appRelative($relative);
        $files->ensureDirectoryExists(dirname($path));
        $files->put($path, 'key-material-'.$userId);
    }
}

function keyDebtAbsolute(string $relative): string
{
    return app(UserDataPathService::class)->appRelative($relative);
}

// Refuses one path by returning false, which is how a held handle reports
// itself: `Filesystem::delete()` never throws for an ordinary refusal.
function keyDebtRefuseUnlinkOf(string $relative): string
{
    $blocked = keyDebtAbsolute($relative);

    $files = new class($blocked) extends Filesystem
    {
        public function __construct(private readonly string $blocked) {}

        public function delete($paths): bool
        {
            return $paths === $this->blocked ? false : parent::delete($paths);
        }
    };

    app()->instance('files', $files);
    app()->instance(Filesystem::class, $files);

    return $blocked;
}

// The handle is released. Nothing in the product does this; it is the next hour
// on a machine where whatever held the file has closed it.
function keyDebtAllowEveryUnlink(): void
{
    $files = new Filesystem;

    app()->instance('files', $files);
    app()->instance(Filesystem::class, $files);
}

/** @return array{claimed: bool, completed: bool} what the debt table says about one account */
function keyDebtRowFor(int $accountId): array
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $row = $db->connection()->table('account_key_purge_state')->where('account_id', $accountId)->first();

    return [
        'claimed' => $row !== null,
        'completed' => $row !== null && $row->completed_at !== null,
    ];
}

// The last write inside the transaction, so the rollback it causes is the
// latest one the deletion can take — later than anything the old ordering
// unlinked before.
function keyDebtRefuseTheClaim(): void
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $db->connection()->statement(
        "CREATE TRIGGER account_key_purge_state_block BEFORE INSERT ON account_key_purge_state
         BEGIN SELECT RAISE(ABORT, 'the deletion could not record what it owes'); END",
    );
}

it('leaves the account every key it had when the deletion rolls back at its last write', function (): void {
    $owner = keyDebtUser('rollback-owner');
    keyDebtUser('rollback-partner');
    keyDebtWriteKeyMaterial($owner->id);
    keyDebtRefuseTheClaim();

    $this->actingAs($owner);

    expect(fn () => app(DeleteAccountAction::class)($owner, 'rollback-owner-password-12'))
        ->toThrow(RuntimeException::class);

    expect(User::query()->where('id', $owner->id)->exists())
        ->toBeTrue('the rollback is the point of this case; without it there is nothing to keep keys for');

    foreach (keyDebtKeyMaterialPaths($owner->id) as $relative) {
        expect(keyDebtAbsolute($relative))->toBeFile(
            $relative.' went with a deletion that did not happen, and every sealed column it opens is '
            .'now ciphertext against an account the reader still has.',
        );
    }

    expect(keyDebtRowFor($owner->id)['claimed'])->toBeFalse('a deletion that rolled back owes nothing');
});

// The refusal that used to roll the deletion back, having already destroyed the
// two paths ahead of it in the list.
it('commits the deletion it performed rather than restoring the account over two destroyed keys', function (): void {
    $log = Mockery::spy(LoggerInterface::class);
    $this->app->instance(LoggerInterface::class, $log);

    $owner = keyDebtUser('refused-owner');
    keyDebtUser('refused-partner');
    keyDebtWriteKeyMaterial($owner->id);

    $this->actingAs($owner);
    $blocked = keyDebtRefuseUnlinkOf('secrets/open-banking/'.$owner->id.'.json');

    app(DeleteAccountAction::class)($owner, 'refused-owner-password-12');

    expect(User::query()->where('id', $owner->id)->exists())->toBeFalse()
        ->and(keyDebtAbsolute('sync/identity/'.$owner->id.'.enc'))->not->toBeFile()
        ->and(keyDebtAbsolute('sync/gdk/'.$owner->id.'.enc'))->not->toBeFile()
        ->and($blocked)->toBeFile();

    expect(keyDebtRowFor($owner->id))->toBe(
        ['claimed' => true, 'completed' => false],
        'the connector secret is still on this disk, so the deletion is owed the rest of itself',
    );

    $log->shouldHaveReceived('error')
        ->withArgs(fn (string $message): bool => str_contains($message, 'key material outlived the deletion'))
        ->once();
});

it('still destroys the sync identity, the keyring and the connector secret when it completes', function (): void {
    $owner = keyDebtUser('complete-owner');
    $partner = keyDebtUser('complete-partner');
    keyDebtWriteKeyMaterial($owner->id);
    keyDebtWriteKeyMaterial($partner->id);

    $this->actingAs($owner);

    app(DeleteAccountAction::class)($owner, 'complete-owner-password-12');

    foreach (keyDebtKeyMaterialPaths($owner->id) as $relative) {
        expect(keyDebtAbsolute($relative))->not->toBeFile($relative.' is what a paired peer would restore the account through');
    }

    foreach (keyDebtKeyMaterialPaths($partner->id) as $relative) {
        expect(keyDebtAbsolute($relative))->toBeFile('the household member keeps their own');
    }

    expect(keyDebtRowFor($owner->id))->toBe(['claimed' => true, 'completed' => true]);
});

it('finishes on the next sweep what a held handle refused', function (): void {
    $log = Mockery::spy(LoggerInterface::class);
    $this->app->instance(LoggerInterface::class, $log);

    $owner = keyDebtUser('swept-owner');
    keyDebtUser('swept-partner');
    keyDebtWriteKeyMaterial($owner->id);

    $this->actingAs($owner);
    $blocked = keyDebtRefuseUnlinkOf('sync/identity/'.$owner->id.'.enc');

    app(DeleteAccountAction::class)($owner, 'swept-owner-password-12');

    $this->artisan('auth:sweep-owed-key-material')->assertExitCode(0);

    expect($blocked)->toBeFile('the handle is still held, so the sweep reports rather than pretends')
        ->and(keyDebtRowFor($owner->id))->toBe(['claimed' => true, 'completed' => false]);

    keyDebtAllowEveryUnlink();

    $this->artisan('auth:sweep-owed-key-material')->assertExitCode(0);

    expect($blocked)->not->toBeFile()
        ->and(keyDebtRowFor($owner->id))->toBe(['claimed' => true, 'completed' => true]);

    $log->shouldHaveReceived('error')
        ->withArgs(fn (string $message): bool => str_contains($message, 'still on this disk'))
        ->once();
});

// A peer can mint an id this device has already deleted once. The debt names an
// account that is gone; an id that is back names somebody whose key material is
// their own, and unlinking it is this defect pointed the other way.
it('leaves alone the key material of an id the schema has handed out again', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $living = keyDebtUser('reminted-owner');
    keyDebtWriteKeyMaterial($living->id);

    $db->connection()->table('account_key_purge_state')->insert([
        'account_id' => $living->id,
        'claimed_at' => '2026-09-12 09:00:00',
        'completed_at' => null,
        'updated_at' => '2026-09-12 09:00:00',
    ]);

    $this->artisan('auth:sweep-owed-key-material')->assertExitCode(0);

    foreach (keyDebtKeyMaterialPaths($living->id) as $relative) {
        expect(keyDebtAbsolute($relative))->toBeFile($relative.' belongs to an account that is here');
    }

    expect(keyDebtRowFor($living->id))->toBe(['claimed' => true, 'completed' => false]);
});

// A phone can be the only device a household owns, and the files are on this
// device's disk, so no peer can finish what a deletion here left behind.
it('schedules the sweep on an expression a phone manifest can carry', function (): void {
    $carried = MobileBackgroundSchedule::carriedBy(app(Schedule::class)->events());

    expect($carried)->toContain('auth:sweep-owed-key-material')
        ->and(MobileBackgroundSchedule::requiredOnDevice())->toHaveKey('auth.sweep-owed-key-material')
        ->and(MobileBackgroundSchedule::desktopOnly())->not->toHaveKey('auth.sweep-owed-key-material');
});
