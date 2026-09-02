<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Public\Events\EntityMutated;
use Modules\Sync\Public\Events\GoalMutated;

uses(RefreshDatabase::class);

// The backfill emits whole rows; a live write emits a payload built by hand,
// and the ones for pots, goals, counterparties and notifications named every
// column except the two timestamps. The same row therefore reached a peer
// complete or with both null, decided only by whether it predated pairing.

function timestampCaptureUser(): User
{
    $user = User::query()->create([
        'username' => 'tscap-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $keypair = sodium_crypto_sign_keypair();

    app()->instance(OpLogWriter::class, app(OpLogWriter::class, [
        'deviceId' => 'tscap-device',
        'userId' => (int) $user->id,
        'secretKey' => sodium_crypto_sign_secretkey($keypair),
        'publicKey' => sodium_crypto_sign_publickey($keypair),
    ]));

    return $user;
}

/** @return array{0: User, 1: int} the user and a pot it owns, stamped at a known moment */
function timestampCapturePot(string $stamp): array
{
    $user = timestampCaptureUser();

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $connection = $db->connection();

    $accountId = $connection->table('accounts')->insertGetId([
        'user_id' => $user->id,
        'name' => 'Timestamp capture',
        'slug' => 'tscap-'.bin2hex(random_bytes(3)),
        'kind' => 'bank',
        'iban' => 'NL00TSCP'.str_pad((string) $user->id, 10, '0', STR_PAD_LEFT),
        'default_currency' => 'EUR',
        'created_at' => $stamp,
        'updated_at' => $stamp,
    ]);

    $potId = (int) $connection->table('pots')->insertGetId([
        'user_id' => $user->id,
        'account_id' => $accountId,
        'name' => 'Vakantie',
        'currency' => 'EUR',
        'status' => 'active',
        'created_at' => $stamp,
        'updated_at' => $stamp,
    ]);

    return [$user, $potId];
}

/** @return array<string, string|null> captured field => value, for this user's pots ops */
function timestampCaptureFields(User $user): array
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $fields = [];

    foreach ($db->connection()->table('op_log_entries')->where('user_id', $user->id)->where('table_name', 'pots')->get() as $row) {
        $fields[(string) $row->field] = $row->value === null ? null : (string) $row->value;
    }

    return $fields;
}

// The payload a hand-built create carries: every column the writer names, and
// neither timestamp. Dispatched rather than driven through PotWriter so this
// pins the capture seam itself, which every table reaches the same way.
function timestampCaptureEvent(User $user, int $potId): EntityMutated
{
    return new EntityMutated(
        table: 'pots',
        pk: $potId,
        userId: (int) $user->id,
        mutationType: 'create',
        dirtyFields: ['user_id' => $user->id, 'name' => 'Vakantie', 'currency' => 'EUR', 'status' => 'active'],
    );
}

it('captures the timestamps a hand-built create payload left out', function (): void {
    [$user, $potId] = timestampCapturePot('2026-03-04 05:06:07');

    app(Dispatcher::class)->dispatch(timestampCaptureEvent($user, $potId));

    $fields = timestampCaptureFields($user);

    // Named columns, not toHaveKey's second argument, which is an expected
    // value rather than a message.
    expect(array_keys($fields))->toContain('created_at')->toContain('updated_at');

    expect($fields['created_at'])->toContain('2026-03-04')
        ->and($fields['updated_at'])->toContain('2026-03-04');
});

it('still captures the columns the payload did name', function (): void {
    [$user, $potId] = timestampCapturePot('2026-03-04 05:06:07');

    app(Dispatcher::class)->dispatch(timestampCaptureEvent($user, $potId));

    expect(array_keys(timestampCaptureFields($user)))
        ->toContain('user_id')
        ->toContain('name')
        ->toContain('currency')
        ->toContain('status');
});

// A payload that already names them is left alone: pot_movements builds its
// row with the timestamps in it, and re-reading would swap a value the writer
// chose for whatever the column happens to hold.
it('keeps the timestamps a payload already carries', function (): void {
    [$user, $potId] = timestampCapturePot('2026-03-04 05:06:07');

    app(Dispatcher::class)->dispatch(new EntityMutated(
        table: 'pots',
        pk: $potId,
        userId: (int) $user->id,
        mutationType: 'create',
        dirtyFields: ['name' => 'Vakantie', 'created_at' => '2019-01-02 03:04:05', 'updated_at' => '2019-01-02 03:04:05'],
    ));

    expect(timestampCaptureFields($user)['created_at'])->toContain('2019-01-02');
});

// The row can be gone by the time the listener reads it back. Losing the
// timestamps then is the old behaviour; losing the whole capture is not.
it('captures what it has when the row is already gone', function (): void {
    [$user, $potId] = timestampCapturePot('2026-03-04 05:06:07');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $db->connection()->table('pots')->where('id', $potId)->delete();

    app(Dispatcher::class)->dispatch(timestampCaptureEvent($user, $potId));

    expect(array_keys(timestampCaptureFields($user)))->toContain('name');
});

// The reason the fill lives in OpLogWriter and not in one listener branch:
// EntityMutated is one of thirteen call sites, and goals never pass through it.
// Fixed in the branch that handles pots, goals would still have travelled bare.
it('captures the timestamps on a path that is not EntityMutated', function (): void {
    $user = timestampCaptureUser();

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $goalId = (int) $db->connection()->table('goals')->insertGetId([
        'user_id' => $user->id,
        'name' => 'Nieuwe fiets',
        'target_minor' => 120000,
        'target_currency' => 'EUR',
        'start_date' => '2026-01-01',
        'target_date' => '2026-12-31',
        'status' => 'active',
        'created_at' => '2026-03-04 05:06:07',
        'updated_at' => '2026-03-04 05:06:07',
    ]);

    app(Dispatcher::class)->dispatch(new GoalMutated(
        goalId: $goalId,
        userId: (int) $user->id,
        mutationType: 'create',
        dirtyFields: ['user_id' => $user->id, 'name' => 'Nieuwe fiets', 'target_minor' => 120000],
    ));

    $fields = [];

    foreach ($db->connection()->table('op_log_entries')->where('user_id', $user->id)->where('table_name', 'goals')->get() as $row) {
        $fields[(string) $row->field] = (string) $row->value;
    }

    expect(array_keys($fields))->toContain('created_at')->toContain('updated_at');
    expect($fields['created_at'])->toContain('2026-03-04');
});
