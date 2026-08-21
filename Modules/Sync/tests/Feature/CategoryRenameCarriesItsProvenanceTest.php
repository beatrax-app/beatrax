<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Category;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\OpLog\QuarantineReason;
use Modules\Sync\Internal\Signing\DeviceKeySigner;

uses(RefreshDatabase::class);

// `name` and `name_is_default` are one fact split over two columns. A peer
// that replayed the rename without the flag would keep translating the slug
// over the top of the user's own words, so the registry has to carry both.
//
// The fixture below is SYNTHETIC and deliberately so. Today nothing writes
// name_is_default = true on a user-owned row — the seeder and the backfill both
// write only user_id IS NULL rows, and a global row is neither published
// (OpLogBackfiller scopes to where('user_id', $userId)) nor writable by a replay
// (RowOwnership::scopeToUser). So the flag and replication cannot meet in
// production, and this file is a mechanism test for the registry entry that
// will govern them when they do. Writing it as though the state were reachable
// is what made it look like a convergence test; it is not one, and the three
// cases below cover the mechanism properly instead: both HLC orders, and the
// version-skew path that is the only one that could plausibly bite.

// $value is the JSON form an op carries on the wire, which is what the LWW
// strategy decodes.
function provenanceSignedSet(string $field, string $value, int $pk, int $userId, string $secretKey, int $hlcL): OpLogEntry
{
    $draft = new OpLogEntry(
        table: 'categories',
        pk: $pk,
        field: $field,
        value: $value,
        hlcL: $hlcL,
        hlcC: 0,
        deviceId: 'device-peer',
        opType: OpType::Set,
        signature: '',
        userId: $userId,
    );

    return new OpLogEntry(
        table: 'categories',
        pk: $pk,
        field: $field,
        value: $value,
        hlcL: $hlcL,
        hlcC: 0,
        deviceId: 'device-peer',
        opType: OpType::Set,
        signature: (new DeviceKeySigner)->sign($draft->signingPayload(), $secretKey),
        userId: $userId,
    );
}

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'sync-cat-provenance',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $this->db = $db;

    $this->categoryId = $db->connection()->table('categories')->insertGetId([
        'user_id' => $this->user->id,
        'name' => 'Groceries',
        'slug' => 'groceries',
        'kind' => 'expense',
        'name_is_default' => true,
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);

    $keypair = sodium_crypto_sign_keypair();
    $this->secretKey = sodium_crypto_sign_secretkey($keypair);
    $this->publicKey = sodium_crypto_sign_publickey($keypair);
});

// The wire values for a rename, as two independent per-column LWW entries.
/**
 * @return list<OpLogEntry>
 */
function provenanceRenameOps(int $pk, int $userId, string $secretKey, int $nameHlc, int $flagHlc): array
{
    return [
        provenanceSignedSet('name', '"Supermarkt"', $pk, $userId, $secretKey, $nameHlc),
        provenanceSignedSet('name_is_default', 'false', $pk, $userId, $secretKey, $flagHlc),
    ];
}

it('replays a peer rename together with the flag that stops the translation', function (): void {
    app()->setLocale('nl');

    /** @var Category $before */
    $before = Category::withoutGlobalScopes()->findOrFail($this->categoryId);
    expect($before->display_name)->toBe('Boodschappen');

    $userId = (int) $this->user->id;
    $entries = [
        provenanceSignedSet('name', '"Supermarkt"', (int) $this->categoryId, $userId, $this->secretKey, 2000),
        provenanceSignedSet('name_is_default', 'false', (int) $this->categoryId, $userId, $this->secretKey, 2001),
    ];

    $replayer = new OpLogReplayer($this->db, ['device-peer' => bin2hex($this->publicKey)]);
    $replayer->replay($entries, $userId);

    /** @var Category $after */
    $after = Category::withoutGlobalScopes()->findOrFail($this->categoryId);
    expect($after->name)->toBe('Supermarkt')
        ->and($after->name_is_default)->toBeFalse()
        ->and($after->display_name)->toBe('Supermarkt');

    app()->setLocale('de');
    /** @var Category $german */
    $german = Category::withoutGlobalScopes()->findOrFail($this->categoryId);
    expect($german->display_name)->toBe('Supermarkt');
});

// A single rename writes both columns in one op batch with adjacent HLCs, but
// nothing guarantees which one a peer sees first — a resend, a partial pull or
// a reordered relay drain can invert them. Both orders have to land on the same
// row, or a device is left translating a slug over the user's own words.
it('converges on the same row whichever of the two ops arrives first', function (): void {
    app()->setLocale('nl');

    $userId = (int) $this->user->id;
    $pk = (int) $this->categoryId;

    // Flag op older than the rename op, delivered first.
    $replayer = new OpLogReplayer($this->db, ['device-peer' => bin2hex($this->publicKey)]);
    $replayer->replay(array_reverse(provenanceRenameOps($pk, $userId, $this->secretKey, 2001, 2000)), $userId);

    /** @var Category $after */
    $after = Category::withoutGlobalScopes()->findOrFail($pk);
    expect($after->name)->toBe('Supermarkt')
        ->and($after->name_is_default)->toBeFalse()
        ->and($after->display_name)->toBe('Supermarkt');

    // Replaying the same batch again is a no-op: LWW compares HLCs, so a
    // redelivered op cannot undo the state it already produced.
    $replayer->replay(provenanceRenameOps($pk, $userId, $this->secretKey, 2000, 2001), $userId);

    /** @var Category $again */
    $again = Category::withoutGlobalScopes()->findOrFail($pk);
    expect($again->name)->toBe('Supermarkt')
        ->and($again->name_is_default)->toBeFalse();
});

// Version skew, and the only case here that could plausibly bite: a peer on a
// build that predates the column receives a name_is_default op. It must not
// wedge the batch — the rename still has to apply — and the unknown op has to
// land in quarantine rather than being dropped silently or crashing the replay.
it('quarantines the flag on a peer whose schema has no such column, and still takes the rename', function (): void {
    Schema::table('categories', static function (Blueprint $table): void {
        $table->dropColumn('name_is_default');
    });

    $userId = (int) $this->user->id;
    $pk = (int) $this->categoryId;

    $replayer = new OpLogReplayer($this->db, ['device-peer' => bin2hex($this->publicKey)]);
    $replayer->replay(provenanceRenameOps($pk, $userId, $this->secretKey, 2000, 2001), $userId);

    $row = $this->db->connection()->table('categories')->where('id', $pk)->first(['name']);
    expect($row?->name)->toBe('Supermarkt');

    $quarantined = $this->db->connection()->table('op_log_quarantine')
        ->where('user_id', $userId)
        ->where('table_name', 'categories')
        ->get(['reason', 'raw_value']);

    expect($quarantined)->toHaveCount(1);
    expect($quarantined->first()?->reason)->toBe(QuarantineReason::UnknownColumn->value);
    expect($quarantined->first()?->raw_value)->toBe('false');
});
