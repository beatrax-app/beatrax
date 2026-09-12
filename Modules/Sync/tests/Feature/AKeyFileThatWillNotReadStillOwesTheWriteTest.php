<?php

declare(strict_types=1);

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Public\Exceptions\BackupIoException;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Sync\Internal\Exceptions\SecretFileException;
use Modules\Sync\Internal\OpLog\DeferredOpCaptureSink;
use Modules\Sync\Internal\OpLog\OpCaptureSinkFactory;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Internal\OpLog\SyncOffOpSink;

uses(RefreshDatabase::class);

// OpCaptureSinkFactory is the one place that decides what an unavailable
// signing key costs, and it answered only BindingResolutionException. The
// identity read behind it raises two more: DeviceIdentityLoader::load()
// declares SecretFileException, and the decryptor under it raises
// BackupIoException when an fopen, read, write or rename fails. The loader
// turns "will not open under this device's key" into a state on purpose and
// leaves a genuine I/O fault as a throw — so an EMFILE, a permissions blip or
// a full volume at the moment of a write went straight past this factory to
// the listener's last-resort catch, which logs and returns.
//
// That is the one exit where nothing is owed afterwards: no deferred
// coordinate, no backfill, and an edit the peer never hears about.

function keyFileUserOwingAPeer(DatabaseManager $db): int
{
    $userId = (int) $db->connection()->table('users')->insertGetId([
        'username' => 'keyfile-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    // A self row and no key-file: the restored-database standing, which owes a
    // peer every write it cannot sign.
    $now = '2026-06-14T00:00:00+00:00';
    $db->connection()->table('device_registry')->insert([
        'user_id' => $userId,
        'device_id' => 'self-'.bin2hex(random_bytes(4)),
        'name' => 'This device',
        'ed25519_public_key_hex' => str_repeat('a', 64),
        'x25519_public_key_hex' => str_repeat('b', 64),
        'safety_number_words' => '',
        'is_self' => 1,
        'paired_at' => $now,
        'confirmed_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    foreach ((array) glob(UserDataPathService::appPath('sync/identity/'.$userId.'.enc*')) as $stale) {
        @unlink((string) $stale);
    }

    return $userId;
}

function sinkFactoryWithWriterRaising(Throwable $raised): OpCaptureSinkFactory
{
    app()->bind(OpLogWriter::class, static function () use ($raised): never {
        throw $raised;
    });

    return app(OpCaptureSinkFactory::class);
}

it('defers the write when the key-file will not read', function (Throwable $raised): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = keyFileUserOwingAPeer($db);

    expect(sinkFactoryWithWriterRaising($raised)->forUser($userId))->toBeInstanceOf(
        DeferredOpCaptureSink::class,
        'a device that owes a peer its writes must owe this one too — the alternative is a mutation nothing ever comes back for',
    );
})->with([
    'the staged plaintext could not be read' => [fn (): Throwable => new SecretFileException('could not read the staged plaintext')],
    'the decryptor could not open the file' => [fn (): Throwable => new BackupIoException('fopen failed on the sealed key-file')],
    'no identity to build a writer from' => [fn (): Throwable => new BindingResolutionException('OpLogWriter: no usable device identity.')],
]);

// The other half of the same decision: a device that owes nobody must not
// start filling a deferral queue nothing will ever drain.
it('still answers a device that owes no peer with the quiet sink', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = (int) $db->connection()->table('users')->insertGetId([
        'username' => 'keyfile-none-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    foreach ((array) glob(UserDataPathService::appPath('sync/identity/'.$userId.'.enc*')) as $stale) {
        @unlink((string) $stale);
    }

    $sink = sinkFactoryWithWriterRaising(new SecretFileException('could not read the staged plaintext'))->forUser($userId);

    expect($sink)->toBeInstanceOf(SyncOffOpSink::class);
});
