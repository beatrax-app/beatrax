<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Http\ResponseTailBudget;
use Modules\Sync\Internal\OpLog\DeferredOpCaptures;
use Modules\Sync\Internal\OpLog\DeferredOpKind;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Internal\OpLog\OpType;

// Measured on a Galaxy A51: /data-devices answered in 12,563 ms after a
// reinstall and 5,034 ms after an app-lock unlock, against 249 ms once the
// queue was empty and 68 ms for the same seven components on a Mac. The op log
// gained 1,133 entries in the minute of the 5,034 ms request. The page is not
// the cost; the backlog the tail drains inside it is.
const BACKLOG_ROWS = 60;

const BACKLOG_FIELDS_PER_ROW = 8;

function aBacklogUser(DatabaseManager $db): User
{
    $user = User::query()->create([
        'username' => 'backlog-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $keypair = sodium_crypto_sign_keypair();

    app()->instance(OpLogWriter::class, app(OpLogWriter::class, [
        'deviceId' => 'backlog-device',
        'userId' => (int) $user->id,
        'secretKey' => sodium_crypto_sign_secretkey($keypair),
        'publicKey' => sodium_crypto_sign_publickey($keypair),
    ]));

    return $user;
}

/** @return list<int> the ids of the rows whose creation is owed to the peer */
function aBacklogOf(DatabaseManager $db, int $userId): array
{
    /** @var DeferredOpCaptures $queue */
    $queue = app(DeferredOpCaptures::class);
    $ids = [];

    foreach (range(1, BACKLOG_ROWS) as $n) {
        $id = (int) $db->connection()->table('recurring_series')->insertGetId([
            'user_id' => $userId,
            'direction' => 'expense',
            'detected_name' => 'Backlog '.$n,
            'state' => 'approved',
            'cadence' => 'monthly',
            'latest_amount_minor' => -1099,
            'latest_currency' => 'EUR',
            'cluster_key' => 'backlog-'.$n.'-'.bin2hex(random_bytes(4)),
            'billing_day' => 7,
            'created_at' => '2026-09-01 00:00:00',
            'updated_at' => '2026-09-04 00:00:15',
        ]);

        foreach (array_slice([
            'direction', 'detected_name', 'state', 'cadence',
            'latest_amount_minor', 'latest_currency', 'cluster_key', 'billing_day',
        ], 0, BACKLOG_FIELDS_PER_ROW) as $field) {
            $queue->record($userId, 'recurring_series', $id, $field, DeferredOpKind::Create);
        }

        $ids[] = $id;
    }

    return $ids;
}

function owedBy(DatabaseManager $db, int $userId): int
{
    return $db->connection()->table('deferred_op_captures')->where('user_id', $userId)->count();
}

function announcedRowsOf(DatabaseManager $db, int $userId): int
{
    return $db->connection()->table('op_log_entries')
        ->where('user_id', $userId)
        ->distinct()
        ->count('pk');
}

// The throttle holds one drain per user per interval, which is what stops a
// polling screen from buying a tick per poll. A test making several requests in
// the same millisecond is the one caller that has to step past it.
function theNextRequestMayDrain(): void
{
    app(Cache::class)->flush();
}

it('drains one row and leaves the rest owed when the wait is already spent', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = aBacklogUser($db);
    $userId = (int) $user->id;

    aBacklogOf($db, $userId);

    expect(owedBy($db, $userId))->toBe(BACKLOG_ROWS * BACKLOG_FIELDS_PER_ROW);

    app()->instance(ResponseTailBudget::class, new ResponseTailBudget(0));

    $this->actingAs($user)->get('/notifications')->assertOk();

    // One row, never none: a tail that can be denied its first unit of work
    // makes no progress on the request after it either, and the queue only
    // grows. The other 59 rows are still owed and still the peer's to receive.
    expect(announcedRowsOf($db, $userId))->toBe(1)
        ->and(owedBy($db, $userId))->toBe((BACKLOG_ROWS - 1) * BACKLOG_FIELDS_PER_ROW);
});

// Bounding what one request pays is only allowed to move the work, never to
// lose it. The whole backlog still reaches the log, each row announced once.
it('announces every owed row exactly once across the requests that follow', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = aBacklogUser($db);
    $userId = (int) $user->id;

    $rows = aBacklogOf($db, $userId);

    app()->instance(ResponseTailBudget::class, new ResponseTailBudget(0));

    foreach (range(1, BACKLOG_ROWS) as $ignored) {
        theNextRequestMayDrain();
        $this->actingAs($user)->get('/notifications')->assertOk();
    }

    expect(owedBy($db, $userId))->toBe(0);

    foreach ($rows as $id) {
        $creates = $db->connection()->table('op_log_entries')
            ->where('user_id', $userId)
            ->where('table_name', 'recurring_series')
            ->where('pk', (string) $id)
            ->where('op_type', OpType::CreateRow->value)
            ->count('field');

        expect($creates)->toBe(BACKLOG_FIELDS_PER_ROW + 2);
    }

    $fieldOps = $db->connection()->table('op_log_entries')->where('user_id', $userId)->count();

    // withRowTimestamps adds created_at and updated_at to every create, and
    // nothing else is on the wire: a second announcement of any row would show
    // up here as a field counted twice.
    expect($fieldOps)->toBe(BACKLOG_ROWS * (BACKLOG_FIELDS_PER_ROW + 2));
});
