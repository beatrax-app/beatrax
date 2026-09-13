<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Services\FieldProvenanceWriter;
use Modules\Sync\Internal\Merge\PlannedRepoint;
use Modules\Sync\Internal\Merge\UntranslatedParentId;
use Modules\Sync\Internal\Merge\UntranslatedParentIds;
use Modules\Sync\Internal\Merge\UntranslatedParentRepair;
use Modules\Sync\Internal\Merge\UntranslatedParentVerdict;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Public\Events\TransactionMutated;
use Modules\Sync\Public\Services\UntranslatedParentHealthCheck;

uses(RefreshDatabase::class);

// Measured on a paired Mac and Galaxy A51, 2026-09-13: forty-two transactions
// the phone sent each kept the phone's counterparty number, because no alias
// existed to rewrite it and `PeerRowAliases::rewriteId()` hands an untranslatable
// id straight back. Each landed on whichever local row wears that number.
const CROSSED_ID_PEER = 'the-phone-whose-counterparties-never-landed';

const CROSSED_ID_SELF = 'the-desktop-that-numbered-them-differently';

function crossedIdUser(): User
{
    return User::query()->create([
        'username' => 'crossedid-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

function crossedIdRegister(DatabaseManager $db, int $userId, string $deviceId, string $keypair, bool $isSelf): void
{
    $db->connection()->table('device_registry')->insert([
        'user_id' => $userId,
        'device_id' => $deviceId,
        'name' => $isSelf ? 'Mac' : 'Galaxy A51',
        'ed25519_public_key_hex' => bin2hex(sodium_crypto_sign_publickey($keypair)),
        'x25519_public_key_hex' => bin2hex(random_bytes(32)),
        'safety_number_words' => 'topic only tornado slot sail sheriff',
        'is_self' => $isSelf ? 1 : 0,
        'paired_at' => '2026-09-10T19:16:13Z',
        'confirmed_at' => '2026-09-10T19:16:13Z',
        'created_at' => '2026-09-10T19:16:13Z',
        'updated_at' => '2026-09-10T19:16:13Z',
    ]);
}

function crossedIdWriter(int $userId, string $deviceId, string $keypair): OpLogWriter
{
    /** @var OpLogWriter $writer */
    $writer = app(OpLogWriter::class, [
        'deviceId' => $deviceId,
        'userId' => $userId,
        'secretKey' => sodium_crypto_sign_secretkey($keypair),
        'publicKey' => sodium_crypto_sign_publickey($keypair),
    ]);

    return $writer;
}

function crossedIdCounterparty(DatabaseManager $db, int $userId, string $slug): int
{
    return (int) $db->connection()->table('counterparties')->insertGetId([
        'user_id' => $userId,
        'type' => 'merchant',
        'slug' => $slug,
        'display_name' => ucfirst($slug),
        'created_at' => '2026-09-10 21:10:03',
        'updated_at' => '2026-09-10 21:10:03',
    ]);
}

// The scaffolding a transaction needs before its counterparty is interesting.
/** @return array{account: int, run: int} */
function crossedIdLedger(DatabaseManager $db, int $userId): array
{
    $account = (int) $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId, 'name' => 'Current', 'slug' => 'current-'.bin2hex(random_bytes(3)),
        'kind' => 'checking', 'iban' => 'NL91ABNA'.random_int(1000000000, 9999999999),
        'default_currency' => 'EUR', 'created_at' => '2026-09-10 19:00:00', 'updated_at' => '2026-09-10 19:00:00',
    ]);

    $run = (int) $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId, 'source_format' => 'csv', 'raw_file_path' => 'fixture.csv',
        'sha256' => hash('sha256', 'fixture'), 'uploaded_at' => '2026-09-10 19:00:00', 'status' => 'confirmed',
        'created_at' => '2026-09-10 19:00:00', 'updated_at' => '2026-09-10 19:00:00',
    ]);

    return ['account' => $account, 'run' => $run];
}

// The row as the applier left it: placed here, and holding the number the peer
// minted because nothing could translate it.
function crossedIdLandedRow(DatabaseManager $db, int $userId, array $ledger, int $counterpartyId, string $ref): int
{
    return (int) $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId, 'account_id' => $ledger['account'], 'type' => 'expense',
        'posted_at' => '2026-02-05', 'booked_at' => '2026-02-05 00:00:00', 'value_date' => '2026-02-05',
        'amount_minor' => -1167, 'currency' => 'EUR', 'settled_amount_minor' => -1167, 'settled_currency' => 'EUR',
        'counterparty_normalized' => 'albert heijn', 'normalization_version' => 1,
        'source_format' => 'csv', 'import_run_id' => $ledger['run'], 'source_row_index' => 1,
        'source_ref' => $ref, 'fingerprint' => hash('sha256', $ref), 'fingerprint_version' => 1,
        'status' => 'cleared', 'counterparty_id' => $counterpartyId,
        'created_at' => '2026-09-10 19:06:56', 'updated_at' => '2026-09-10 19:06:56',
    ]);
}

/** @param array<string, mixed> $fields */
function crossedIdPeerCreate(OpLogWriter $writer, string $table, int|string $pk, array $fields): void
{
    $writer->writeCreateRow(table: $table, pk: $pk, fields: $fields);
}

/** @return list<PlannedRepoint> */
function crossedIdPlan(int $userId): array
{
    return app(UntranslatedParentRepair::class)->plan($userId)['plans'];
}

// The counterparty column alone: a transaction names four parents and this
// suite is about the one whose alias was never recorded.
function crossedIdFor(int $userId, int $transactionId): ?PlannedRepoint
{
    foreach (crossedIdPlan($userId) as $plan) {
        if ($plan->finding->at->localId === (string) $transactionId && $plan->finding->at->column === 'counterparty_id') {
            return $plan;
        }
    }

    return null;
}

// The peer's numbers for the account and the run, aliased onto this device's
// the way the applier records them when it re-homes a create. Without them
// every column of the row reads as an id nothing speaks for, which is a
// different defect and not the one under test.
function crossedIdAliasParents(DatabaseManager $db, int $userId, array $ledger): void
{
    $db->connection()->table('op_log_row_aliases')->insert([
        ['user_id' => $userId, 'table_name' => 'accounts', 'device_id' => CROSSED_ID_PEER, 'remote_id' => '2', 'local_id' => (string) $ledger['account'], 'created_at' => '2026-09-10 19:06:44'],
        ['user_id' => $userId, 'table_name' => 'import_runs', 'device_id' => CROSSED_ID_PEER, 'remote_id' => '3', 'local_id' => (string) $ledger['run'], 'created_at' => '2026-09-10 19:06:56'],
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-09-13 16:00:00');

    $this->db = app(DatabaseManager::class);
    $this->user = crossedIdUser();
    $this->userId = (int) $this->user->id;
    $this->peerKeypair = sodium_crypto_sign_keypair();
    $this->selfKeypair = sodium_crypto_sign_keypair();

    crossedIdRegister($this->db, $this->userId, CROSSED_ID_SELF, $this->selfKeypair, true);
    crossedIdRegister($this->db, $this->userId, CROSSED_ID_PEER, $this->peerKeypair, false);

    $this->peer = crossedIdWriter($this->userId, CROSSED_ID_PEER, $this->peerKeypair);
    $this->ledger = crossedIdLedger($this->db, $this->userId);

    // Two rows the desktop minted. The peer's create for `albert-heijn` carries
    // the number `ziggo` wears here, which is the whole defect in two rows.
    $this->ziggo = crossedIdCounterparty($this->db, $this->userId, 'ziggo');
    $this->albertHeijn = crossedIdCounterparty($this->db, $this->userId, 'albert-heijn');

    crossedIdPeerCreate($this->peer, 'counterparties', $this->ziggo, [
        'user_id' => $this->userId, 'type' => 'merchant', 'slug' => 'albert-heijn',
        'display_name' => 'Albert Heijn', 'created_at' => '2026-09-10 19:06:45', 'updated_at' => '2026-09-10 19:06:45',
    ]);

    crossedIdAliasParents($this->db, $this->userId, $this->ledger);

    crossedIdPeerCreate($this->peer, 'transactions', 4, [
        'user_id' => $this->userId, 'account_id' => 2, 'import_run_id' => 3,
        'counterparty_id' => $this->ziggo, 'category_id' => null,
        'fingerprint' => hash('sha256', 'peer-4'), 'source_ref' => 'peer-4',
    ]);

    $this->transactionId = crossedIdLandedRow($this->db, $this->userId, $this->ledger, $this->ziggo, 'peer-4');

    $this->db->connection()->table('op_log_row_aliases')->insert([
        'user_id' => $this->userId, 'table_name' => 'transactions', 'device_id' => CROSSED_ID_PEER,
        'remote_id' => '4', 'local_id' => (string) $this->transactionId, 'created_at' => '2026-09-10 19:06:56',
    ]);
});

it('names the row whose parent id crossed before an alias could translate it', function (): void {
    $plan = crossedIdFor($this->userId, $this->transactionId);

    expect($plan)->toBeInstanceOf(PlannedRepoint::class)
        ->and($plan->finding->verdict)->toBe(UntranslatedParentVerdict::Misfiled)
        ->and($plan->finding->storedValue)->toBe((string) $this->ziggo)
        ->and($plan->finding->correctValue)->toBe((string) $this->albertHeijn)
        ->and($plan->action)->toBe(PlannedRepoint::REPOINT)
        ->and($plan->finding->evidence)->toContain('slug=albert-heijn');
});

it('changes nothing until the repair is applied', function (): void {
    crossedIdPlan($this->userId);

    expect($this->db->connection()->table('transactions')->where('id', $this->transactionId)->value('counterparty_id'))
        ->toBe($this->ziggo)
        ->and($this->db->connection()->table('op_log_row_aliases')->where('table_name', 'counterparties')->count())
        ->toBe(0);
});

it('repoints the row, records the alias the applier missed, and announces the move', function (): void {
    app()->instance(OpLogWriter::class, crossedIdWriter($this->userId, CROSSED_ID_SELF, $this->selfKeypair));

    $result = app(UntranslatedParentRepair::class)->apply(crossedIdPlan($this->userId), $this->user);

    expect($result['repointed'])->toBe(1)
        ->and($result['aliased'])->toBe(1)
        ->and($this->db->connection()->table('transactions')->where('id', $this->transactionId)->value('counterparty_id'))
        ->toBe($this->albertHeijn);

    // What a peer sees. Without it the create this device backfilled is still
    // the log's latest word on the column, so a rebuild resolves back onto the
    // wrong id and the repair is undone by the next catch-up.
    $announced = $this->db->connection()->table('op_log_entries')
        ->where('device_id', CROSSED_ID_SELF)
        ->where('table_name', 'transactions')
        ->where('pk', (string) $this->transactionId)
        ->where('field', 'counterparty_id')
        ->where('op_type', OpType::Set->value)
        ->value('value');

    expect($announced)->toBe(json_encode($this->albertHeijn));

    expect($this->db->connection()->table('op_log_row_aliases')
        ->where('table_name', 'counterparties')->where('device_id', CROSSED_ID_PEER)
        ->where('remote_id', (string) $this->ziggo)->value('local_id'))->toBe((string) $this->albertHeijn);
});

it('repairs nothing on a second run', function (): void {
    app()->instance(OpLogWriter::class, crossedIdWriter($this->userId, CROSSED_ID_SELF, $this->selfKeypair));
    $repair = app(UntranslatedParentRepair::class);

    $repair->apply($repair->plan($this->userId)['plans'], $this->user);
    $second = $repair->apply($repair->plan($this->userId)['plans'], $this->user);

    expect($second['repointed'])->toBe(0)
        ->and($second['aliased'])->toBe(0)
        ->and($second['refused'])->toBe(0)
        ->and(crossedIdFor($this->userId, $this->transactionId))->toBeNull();
});

it('refuses a row whose peer counterparty no row here holds, and says which repair places it', function (): void {
    $db = $this->db;
    $db->connection()->table('counterparties')->where('id', $this->albertHeijn)->delete();

    $plan = crossedIdFor($this->userId, $this->transactionId);

    expect($plan->finding->verdict)->toBe(UntranslatedParentVerdict::Unplaceable)
        ->and($plan->finding->correctValue)->toBeNull()
        ->and($plan->action)->toBe(UntranslatedParentRepair::NOT_HERE_YET);

    app()->instance(OpLogWriter::class, crossedIdWriter($this->userId, CROSSED_ID_SELF, $this->selfKeypair));

    expect(app(UntranslatedParentRepair::class)->apply(crossedIdPlan($this->userId), $this->user)['repointed'])->toBe(0)
        ->and($db->connection()->table('transactions')->where('id', $this->transactionId)->value('counterparty_id'))
        ->toBe($this->ziggo);
});

it('refuses a parent id no create in the log speaks for', function (): void {
    $this->db->connection()->table('op_log_entries')
        ->where('table_name', 'counterparties')->where('device_id', CROSSED_ID_PEER)->delete();

    $plan = crossedIdFor($this->userId, $this->transactionId);

    expect($plan->finding->verdict)->toBe(UntranslatedParentVerdict::Unspoken)
        ->and($plan->action)->toBe(UntranslatedParentRepair::NOTHING_SPEAKS);
});

// Worse than leaving the row wrong: the row is visibly wrong and a lost
// correction is not.
it('leaves a column the reader claimed by hand', function (): void {
    app(FieldProvenanceWriter::class)->stamp($this->userId, $this->transactionId, ['counterparty_id' => 'manual']);

    $plan = crossedIdFor($this->userId, $this->transactionId);

    expect($plan->finding->verdict)->toBe(UntranslatedParentVerdict::Misfiled)
        ->and($plan->action)->toBe(UntranslatedParentRepair::PROTECTED);

    app()->instance(OpLogWriter::class, crossedIdWriter($this->userId, CROSSED_ID_SELF, $this->selfKeypair));

    expect(app(UntranslatedParentRepair::class)->apply(crossedIdPlan($this->userId), $this->user)['repointed'])->toBe(0)
        ->and($this->db->connection()->table('transactions')->where('id', $this->transactionId)->value('counterparty_id'))
        ->toBe($this->ziggo);
});

// Two autoincrements reach the same number independently, so the same pk from
// two devices is not two devices talking about one row. Read without this the
// census called two of the measured forty-two correct because the desktop's own
// fourteenth transaction happened to carry the same counterparty number.
it('does not read a shared pk as two devices agreeing about the row', function (): void {
    crossedIdWriter($this->userId, CROSSED_ID_SELF, $this->selfKeypair)->writeCreateRow(
        table: 'transactions',
        pk: 4,
        fields: ['user_id' => $this->userId, 'counterparty_id' => $this->ziggo, 'fingerprint' => hash('sha256', 'desktop-4'), 'source_ref' => 'desktop-4'],
    );

    expect(crossedIdFor($this->userId, $this->transactionId)?->finding->verdict)->toBe(UntranslatedParentVerdict::Misfiled);
});

// The same pk from two devices IS one row where the natural key agrees, which
// is what a derived primary key guarantees. Twenty-one recurring occurrences on
// the measured database are correct for exactly this reason.
it('reads a shared pk as agreement where the natural key agrees', function (): void {
    crossedIdWriter($this->userId, CROSSED_ID_SELF, $this->selfKeypair)->writeCreateRow(
        table: 'transactions',
        pk: 4,
        fields: ['user_id' => $this->userId, 'counterparty_id' => $this->ziggo, 'fingerprint' => hash('sha256', 'peer-4'), 'source_ref' => 'peer-4'],
    );

    expect(crossedIdFor($this->userId, $this->transactionId))->toBeNull();
});

it('names the ids at the doctor before the repair and stops naming them after', function (): void {
    $check = app(UntranslatedParentHealthCheck::class);

    expect($check->severity())->toBe('warning')
        ->and($check->message())->toContain('name a different row here than the peer meant');

    app()->instance(OpLogWriter::class, crossedIdWriter($this->userId, CROSSED_ID_SELF, $this->selfKeypair));
    app(UntranslatedParentRepair::class)->apply(crossedIdPlan($this->userId), $this->user);

    expect(app(UntranslatedParentHealthCheck::class)->severity())->toBe('ok');
});

// The denominator, counted rather than floored: a census that stopped looking
// reports the same silence as one that looked at every id and found nothing.
it('counts every parent id it read, not only the ones it faulted', function (): void {
    $census = app(UntranslatedParentIds::class)->census($this->userId);

    expect($census['checked'])->toBe(3)
        ->and($census['agreed'])->toBe(2)
        ->and($census['findings'])->toHaveCount(1)
        ->and($census['findings'][0])->toBeInstanceOf(UntranslatedParentId::class);
});

it('dispatches the edit the capture listener turns into an op', function (): void {
    $seen = [];
    app(Dispatcher::class)->listen(TransactionMutated::class, function (TransactionMutated $event) use (&$seen): void {
        $seen[] = $event->dirtyFields;
    });

    app()->instance(OpLogWriter::class, crossedIdWriter($this->userId, CROSSED_ID_SELF, $this->selfKeypair));
    app(UntranslatedParentRepair::class)->apply(crossedIdPlan($this->userId), $this->user);

    expect($seen)->toContain(['counterparty_id' => $this->albertHeijn]);
});
