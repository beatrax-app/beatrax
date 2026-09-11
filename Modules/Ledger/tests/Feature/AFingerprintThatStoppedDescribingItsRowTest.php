<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Dto\FingerprintTuple;
use Modules\Ledger\Public\Services\FingerprintComposer;
use Modules\Sync\Public\Events\PeerRowsApplied;

uses(RefreshDatabase::class);

// The fingerprint is the dedup key and is composed OVER seven columns of the
// row it keys. A merge seats each column independently, so the digest can end
// up describing neither device's row -- and the next import of the same
// statement then finds no match and lands a duplicate.
function fpdUser(): User
{
    return User::query()->create([
        'username' => 'fpd-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
}

function fpdTransaction(int $userId, int $amountMinor, string $normalized, int $version): int
{
    $suffix = bin2hex(random_bytes(4));

    $accountId = (int) DB::table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'ASN fpd',
        'slug' => 'fpd-'.$suffix,
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'created_at' => '2026-07-01 00:00:00',
        'updated_at' => '2026-07-01 00:00:00',
    ]);

    $runId = (int) DB::table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/fpd-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'fpd-'.$suffix),
        'uploaded_at' => '2026-07-01 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-07-01 00:00:00',
        'updated_at' => '2026-07-01 00:00:00',
    ]);

    return (int) DB::table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'type' => 'expense',
        'posted_at' => '2026-07-03',
        'booked_at' => '2026-07-03 12:00:00',
        'value_date' => '2026-07-03',
        'amount_minor' => $amountMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $amountMinor,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => $normalized,
        'normalization_version' => 4,
        'source_format' => 'asn-csv',
        'import_run_id' => $runId,
        'source_row_index' => 0,
        // The digest the OTHER device composed, over the amount IT held.
        'fingerprint' => hash('sha256', 'a-digest-for-values-this-row-no-longer-has'),
        'fingerprint_version' => $version,
        'created_at' => '2026-07-01 00:00:00',
        'updated_at' => '2026-07-01 00:00:00',
    ]);
}

function fpdExpected(int $userId, int $txId): string
{
    /** @var FingerprintComposer $composer */
    $composer = app(FingerprintComposer::class);
    $row = DB::table('transactions')->where('id', $txId)->first();

    return $composer->composeTuple(new FingerprintTuple(
        userId: $userId,
        accountId: (int) $row->account_id,
        postedAtDate: substr((string) $row->posted_at, 0, 10),
        bookedAtDateTime: (string) $row->booked_at,
        amountMinor: (int) $row->amount_minor,
        currency: (string) $row->currency,
        counterpartyNormalized: (string) $row->counterparty_normalized,
        occurrenceOrdinal: (int) $row->occurrence_ordinal,
    ));
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-07-10 09:00:00');
    $this->user = fpdUser();
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $this->db = $db;
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('recomposes a digest the merged row no longer matches', function (): void {
    $version = app(FingerprintComposer::class)->version();
    $txId = fpdTransaction((int) $this->user->id, -1299, 'albert heijn', $version);

    $stale = DB::table('transactions')->where('id', $txId)->value('fingerprint');

    app('events')->dispatch(new PeerRowsApplied(
        userId: (int) $this->user->id,
        updated: ['transactions' => [$txId]],
    ));

    $after = DB::table('transactions')->where('id', $txId)->value('fingerprint');

    expect($after)->not->toBe($stale)
        ->and($after)->toBe(fpdExpected((int) $this->user->id, $txId));
});

// The control. A row whose digest already describes it is left exactly alone,
// so the listener cannot be rewriting every row a replay touches.
it('leaves a digest that already describes its row', function (): void {
    $version = app(FingerprintComposer::class)->version();
    $txId = fpdTransaction((int) $this->user->id, -1299, 'albert heijn', $version);

    DB::table('transactions')->where('id', $txId)
        ->update(['fingerprint' => fpdExpected((int) $this->user->id, $txId)]);

    $before = DB::table('transactions')->where('id', $txId)->value('fingerprint');

    app('events')->dispatch(new PeerRowsApplied(
        userId: (int) $this->user->id,
        updated: ['transactions' => [$txId]],
    ));

    expect(DB::table('transactions')->where('id', $txId)->value('fingerprint'))->toBe($before);
});

// A row below the current version belongs to the sweep that owns the move to
// it. Rewriting it here would migrate it under a stamp saying it never was.
it('does not quietly migrate a row an older version still stamps', function (): void {
    $txId = fpdTransaction((int) $this->user->id, -1299, 'albert heijn', 1);

    $before = DB::table('transactions')->where('id', $txId)->value('fingerprint');

    app('events')->dispatch(new PeerRowsApplied(
        userId: (int) $this->user->id,
        updated: ['transactions' => [$txId]],
    ));

    expect(DB::table('transactions')->where('id', $txId)->value('fingerprint'))->toBe($before);
});

// Another reader's row is not this event's to touch.
it('stays inside the user the replay ran for', function (): void {
    $other = fpdUser();
    $version = app(FingerprintComposer::class)->version();
    $txId = fpdTransaction((int) $other->id, -1299, 'albert heijn', $version);

    $before = DB::table('transactions')->where('id', $txId)->value('fingerprint');

    app('events')->dispatch(new PeerRowsApplied(
        userId: (int) $this->user->id,
        updated: ['transactions' => [$txId]],
    ));

    expect(DB::table('transactions')->where('id', $txId)->value('fingerprint'))->toBe($before);
});

// A peer's transaction arrives as a CreateRow, not as a field merge, and the
// applier translates its account_id to the one this device uses on the way in.
// The digest is composed over that id and travels verbatim, so a created row
// is the commonest way one stops describing itself -- and the listener read
// only the updated map.
it('recomposes a digest on a row the merge created, not only one it rewrote', function (): void {
    $version = app(FingerprintComposer::class)->version();
    $txId = fpdTransaction((int) $this->user->id, -1299, 'albert heijn', $version);

    $stale = DB::table('transactions')->where('id', $txId)->value('fingerprint');

    app('events')->dispatch(new PeerRowsApplied(
        userId: (int) $this->user->id,
        created: ['transactions' => [$txId]],
    ));

    expect(DB::table('transactions')->where('id', $txId)->value('fingerprint'))
        ->not->toBe($stale)
        ->and(DB::table('transactions')->where('id', $txId)->value('fingerprint'))
        ->toBe(fpdExpected((int) $this->user->id, $txId));
});

// A row named in both maps is read once. The union is what makes that true,
// and a second pass would be harmless but would hide a double announcement.
it('reads a row named as both created and updated exactly once', function (): void {
    $version = app(FingerprintComposer::class)->version();
    $txId = fpdTransaction((int) $this->user->id, -1299, 'albert heijn', $version);

    app('events')->dispatch(new PeerRowsApplied(
        userId: (int) $this->user->id,
        created: ['transactions' => [$txId]],
        updated: ['transactions' => [$txId]],
    ));

    expect(DB::table('transactions')->where('id', $txId)->value('fingerprint'))
        ->toBe(fpdExpected((int) $this->user->id, $txId));
});
