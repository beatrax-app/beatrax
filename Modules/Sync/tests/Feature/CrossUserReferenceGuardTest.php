<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\Signing\DeviceKeySigner;

uses(RefreshDatabase::class);

// References were gated on creates but not on the Set that follows one. Both
// entries are validly signed under the sender's own user, so every scope check
// passes and the UPDATE is bounded to their own rows. What was unguarded is the
// value: an id naming a row that belongs to somebody else.

function refUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function refAccount(DatabaseManager $db, int $userId, string $suffix): int
{
    return $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'Ref account '.$suffix,
        'slug' => 'ref-acct-'.$suffix,
        'kind' => 'bank',
        'iban' => 'NL00REFB'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);
}

function refCategory(DatabaseManager $db, int $userId, string $suffix): int
{
    return $db->connection()->table('categories')->insertGetId([
        'user_id' => $userId,
        'name' => 'RefCat '.$suffix,
        'slug' => 'ref-cat-'.$suffix,
        'kind' => 'expense',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);
}

function refTransaction(DatabaseManager $db, int $userId, int $accountId, string $suffix): int
{
    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/ref-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'ref-run-'.$suffix),
        'uploaded_at' => '2026-06-01 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);

    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'ref-'.$suffix),
        'posted_at' => '2026-06-01',
        'booked_at' => '2026-06-01 10:00:00',
        'value_date' => '2026-06-01',
        // transactions carries a natural-key UNIQUE across amount and
        // counterparty, so two fixture rows for one user must differ by more
        // than their fingerprint or the second insert is rejected.
        'amount_minor' => -4999 - crc32($suffix) % 1000,
        'currency' => 'EUR',
        'settled_amount_minor' => -4999 - crc32($suffix) % 1000,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'albert heijn',
        'counterparty_name' => 'ALBERT HEIJN',
        'normalization_version' => 3,
        'description' => 'cross-user reference fixture',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-14 10:00:00');

    $this->u1 = refUser('ref-u1');
    $this->u2 = refUser('ref-u2');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $this->acct1 = refAccount($db, (int) $this->u1->id, 'u1');
    $this->acct2 = refAccount($db, (int) $this->u2->id, 'u2');
    $this->cat1 = refCategory($db, (int) $this->u1->id, 'u1');
    $this->cat2 = refCategory($db, (int) $this->u2->id, 'u2');
    $this->txn1 = refTransaction($db, (int) $this->u1->id, $this->acct1, 'u1');
    $this->txn2 = refTransaction($db, (int) $this->u2->id, $this->acct2, 'u2');

    $this->mapRow = $db->connection()->table('migration_source_map')->insertGetId([
        'user_id' => $this->u1->id,
        'source_product' => 'ynab',
        'source_entity_type' => 'transaction',
        'source_external_id' => 'ext-1',
        'beatrax_entity_type' => 'transaction',
        'beatrax_id' => $this->txn1,
        'natural_key' => 'nk-1',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);

    $this->pot1 = $db->connection()->table('pots')->insertGetId([
        'user_id' => $this->u1->id,
        'account_id' => $this->acct1,
        'name' => 'Holiday',
        'currency' => 'EUR',
        'status' => 'active',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);

    $keypair = sodium_crypto_sign_keypair();
    $this->sk = sodium_crypto_sign_secretkey($keypair);
    $this->signer = new DeviceKeySigner;
    $this->deviceKeys = ['device-ref' => bin2hex(sodium_crypto_sign_publickey($keypair))];
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

// A validly signed Set authored by the user themselves. Nothing about the
// entry is malformed — the whole point is that every existing check passes.
function refSignedSet(string $table, int $pk, string $field, string $value, int $userId, int $hlc): OpLogEntry
{
    $fields = [
        'table' => $table,
        'pk' => $pk,
        'field' => $field,
        'value' => $value,
        'hlcL' => $hlc,
        'hlcC' => 0,
        'deviceId' => 'device-ref',
        'opType' => OpType::Set,
        'userId' => $userId,
    ];

    $stub = new OpLogEntry(...[...$fields, 'signature' => '']);

    return new OpLogEntry(...[...$fields, 'signature' => test()->signer->sign($stub->signingPayload(), test()->sk)]);
}

// The reason matters as much as the refusal. u2's category EXISTS, so the
// database foreign key is perfectly satisfied by this write — nothing but the
// ownership guard stands between the op and another member's category.
function refSignedCreate(string $table, int $pk, string $field, string $value, int $userId, int $hlc): OpLogEntry
{
    $fields = [
        'table' => $table,
        'pk' => $pk,
        'field' => $field,
        'value' => $value,
        'hlcL' => $hlc,
        'hlcC' => 0,
        'deviceId' => 'device-ref',
        'opType' => OpType::CreateRow,
        'userId' => $userId,
    ];

    $stub = new OpLogEntry(...[...$fields, 'signature' => '']);

    return new OpLogEntry(...[...$fields, 'signature' => test()->signer->sign($stub->signingPayload(), test()->sk)]);
}

function refQuarantineReason(DatabaseManager $db, string $table): ?string
{
    $reason = $db->connection()->table('op_log_quarantine')->where('table_name', $table)->value('reason');

    return is_string($reason) ? $reason : null;
}

it('refuses a Set that repoints a transaction at another household member\'s category', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $entry = refSignedSet('transactions', $this->txn1, 'category_id', (string) $this->cat2, (int) $this->u1->id, 1000);

    (new OpLogReplayer($db, $this->deviceKeys))->replay([$entry], (int) $this->u1->id);

    $categoryId = $db->connection()->table('transactions')->where('id', $this->txn1)->value('category_id');

    expect($categoryId)->toBeNull('a Set naming another user\'s category must not be written')
        ->and(refQuarantineReason($db, 'transactions'))->toBe('cross_user');
});

it('refuses a Set that repoints a pot at another household member\'s account', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $entry = refSignedSet('pots', $this->pot1, 'account_id', (string) $this->acct2, (int) $this->u1->id, 1000);

    (new OpLogReplayer($db, $this->deviceKeys))->replay([$entry], (int) $this->u1->id);

    $accountId = $db->connection()->table('pots')->where('id', $this->pot1)->value('account_id');

    expect((int) $accountId)->toBe($this->acct1, 'the pot must still be backed by its own owner\'s account')
        ->and(refQuarantineReason($db, 'pots'))->toBe('cross_user');
});

it('still applies a Set naming the user\'s OWN category, so the guard is not simply refusing everything', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $entry = refSignedSet('transactions', $this->txn1, 'category_id', (string) $this->cat1, (int) $this->u1->id, 1000);

    (new OpLogReplayer($db, $this->deviceKeys))->replay([$entry], (int) $this->u1->id);

    $categoryId = $db->connection()->table('transactions')->where('id', $this->txn1)->value('category_id');

    expect((int) $categoryId)->toBe($this->cat1)
        ->and(refQuarantineReason($db, 'transactions'))->toBeNull();
});

// An id nobody holds is an ordering problem, not theft, and the two must not
// be conflated: reading this back as cross_user would blame a household member
// for a race. The row is still not written here, but the database's own
// foreign key is what refuses it, and it says so under a different reason.
it('does not call an absent reference cross-user, since absence is ordering and not theft', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $entry = refSignedSet('transactions', $this->txn1, 'category_id', '987654', (int) $this->u1->id, 1000);

    (new OpLogReplayer($db, $this->deviceKeys))->replay([$entry], (int) $this->u1->id);

    expect(refQuarantineReason($db, 'transactions'))->not->toBe('cross_user');
});

// The create path always had the guard; what it lacked was coverage. `pots`
// was absent from the hand-written reference list entirely, so this row —
// NOT NULL account_id, joined for display alongside the account's name and
// IBAN — was written against another member's account unchallenged.
it('refuses a CreateRow for a pot backed by another household member\'s account', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $pk = 90210;
    $entries = [
        refSignedCreate('pots', $pk, 'name', '"Stolen"', (int) $this->u1->id, 2000),
        refSignedCreate('pots', $pk, 'currency', '"EUR"', (int) $this->u1->id, 2001),
        refSignedCreate('pots', $pk, 'account_id', (string) $this->acct2, (int) $this->u1->id, 2002),
    ];

    (new OpLogReplayer($db, $this->deviceKeys))->replay($entries, (int) $this->u1->id);

    expect($db->connection()->table('pots')->where('id', $pk)->exists())->toBeFalse()
        ->and(refQuarantineReason($db, 'pots'))->toBe('cross_user');
});

it('applies the same CreateRow when the pot is backed by the user\'s own account', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $pk = 90211;
    $entries = [
        refSignedCreate('pots', $pk, 'name', '"Holiday two"', (int) $this->u1->id, 2000),
        refSignedCreate('pots', $pk, 'currency', '"EUR"', (int) $this->u1->id, 2001),
        refSignedCreate('pots', $pk, 'account_id', (string) $this->acct1, (int) $this->u1->id, 2002),
    ];

    (new OpLogReplayer($db, $this->deviceKeys))->replay($entries, (int) $this->u1->id);

    expect((int) $db->connection()->table('pots')->where('id', $pk)->value('account_id'))->toBe($this->acct1);
});

// beatrax_id has no foreign key and cannot have one: the table it points at
// is whatever beatrax_entity_type says. Derivation is blind to it, and this
// Set carries only the id, so the type has to be read back off the row.
it('refuses a Set that repoints a migration map row at another household member\'s transaction', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $entry = refSignedSet('migration_source_map', $this->mapRow, 'beatrax_id', (string) $this->txn2, (int) $this->u1->id, 3000);

    (new OpLogReplayer($db, $this->deviceKeys))->replay([$entry], (int) $this->u1->id);

    expect((int) $db->connection()->table('migration_source_map')->where('id', $this->mapRow)->value('beatrax_id'))
        ->toBe($this->txn1)
        ->and(refQuarantineReason($db, 'migration_source_map'))->toBe('cross_user');
});

it('applies the same Set when the migration map row names the user\'s own transaction', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $other = refTransaction($db, (int) $this->u1->id, $this->acct1, 'u1-second');
    $entry = refSignedSet('migration_source_map', $this->mapRow, 'beatrax_id', (string) $other, (int) $this->u1->id, 3000);

    (new OpLogReplayer($db, $this->deviceKeys))->replay([$entry], (int) $this->u1->id);

    expect((int) $db->connection()->table('migration_source_map')->where('id', $this->mapRow)->value('beatrax_id'))->toBe($other);
});
