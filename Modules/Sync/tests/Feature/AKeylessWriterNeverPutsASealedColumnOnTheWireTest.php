<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Internal\OpLog\OpLogWriter;

uses(RefreshDatabase::class);

// SensitiveColumnCodec refuses an unsealable write at the column: encryption is
// enabled and no epoch key is held, so the plaintext does not reach the disk.
// The op log is the same plaintext on the same disk and then on the wire, and
// this writer put it there without a word — the state a part-way re-wrap leaves,
// where the identity key-file opens and the keyring does not.

function keylessWriterUser(string $name): User
{
    return User::query()->create([
        'username' => $name.'-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function keylessWriterFor(int $userId): OpLogWriter
{
    $keypair = sodium_crypto_sign_keypair();

    /** @var OpLogWriter $writer */
    $writer = app(OpLogWriter::class, [
        'deviceId' => 'keyless-writer-device',
        'userId' => $userId,
        'secretKey' => sodium_crypto_sign_secretkey($keypair),
        'publicKey' => sodium_crypto_sign_publickey($keypair),
    ]);

    return $writer;
}

/** @return list<object> */
function keylessWriterOps(DatabaseManager $db, int $userId): array
{
    return $db->connection()->table('op_log_entries')->where('user_id', $userId)->get()->all();
}

it('does not write a sealed column into the op log in the clear', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    /** @var Session $session */
    $session = $this->app->make(Session::class);

    $user = keylessWriterUser('keyless-set');
    $this->app->make(GdkKeyringService::class)->generateAndPersist($user->id, $session);

    $writer = keylessWriterFor($user->id);

    // The identity is open and the keyring is not, which is what a re-wrap that
    // stopped part-way leaves behind.
    $session->forget(AppLockTestHarness::HELD_KEY_SESSION_KEY);

    $writer->writeSet('counterparties', 4242, 'display_name', 'ALBERT HEIJN');

    $ops = keylessWriterOps($db, $user->id);

    expect($ops)->toBe([], 'nothing may be announced that this device could not seal');

    $deferred = $db->connection()->table('deferred_op_captures')->where('user_id', $user->id)->get();

    expect($deferred)->toHaveCount(1)
        ->and($deferred[0]->field)->toBe('display_name')
        ->and($deferred[0]->pk)->toBe('4242');
});

it('defers the whole create rather than announcing the columns it could seal', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    /** @var Session $session */
    $session = $this->app->make(Session::class);

    $user = keylessWriterUser('keyless-create');
    $this->app->make(GdkKeyringService::class)->generateAndPersist($user->id, $session);

    $writer = keylessWriterFor($user->id);
    $session->forget(AppLockTestHarness::HELD_KEY_SESSION_KEY);

    $writer->writeCreateRow('counterparties', 77, [
        'type' => 'merchant',
        'slug' => 'albert-heijn',
        'display_name' => 'ALBERT HEIJN',
    ]);

    expect(keylessWriterOps($db, $user->id))->toBe([], 'a create announced without its sealed columns is a row told about twice');

    $fields = $db->connection()->table('deferred_op_captures')
        ->where('user_id', $user->id)
        ->pluck('field')
        ->all();

    sort($fields);

    expect($fields)->toBe(['display_name', 'slug', 'type']);
});

// The other half of the same question, and the one that keeps this from being a
// refusal to write at all: a user who never turned encryption on has nothing to
// seal, so the clear value is the right one and always was.
it('still writes in the clear for a user whose rows are not sealed', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    $user = keylessWriterUser('never-enrolled');

    keylessWriterFor($user->id)->writeSet('counterparties', 4242, 'display_name', 'ALBERT HEIJN');

    $ops = keylessWriterOps($db, $user->id);

    expect($ops)->toHaveCount(1)
        ->and($ops[0]->value)->toBe(json_encode('ALBERT HEIJN'))
        ->and($ops[0]->gdk_epoch)->toBeNull()
        ->and($db->connection()->table('deferred_op_captures')->where('user_id', $user->id)->count())->toBe(0);
});

it('seals the column when the key is in reach', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    /** @var Session $session */
    $session = $this->app->make(Session::class);

    $user = keylessWriterUser('keyed');
    $epoch = $this->app->make(GdkKeyringService::class)->generateAndPersist($user->id, $session);

    keylessWriterFor($user->id)->writeSet('counterparties', 4242, 'display_name', 'ALBERT HEIJN');

    $ops = keylessWriterOps($db, $user->id);

    expect($ops)->toHaveCount(1)
        ->and((int) $ops[0]->gdk_epoch)->toBe($epoch->epochId)
        ->and((string) $ops[0]->value)->not->toContain('ALBERT HEIJN');
});
