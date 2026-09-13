<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Internal\OpLog\OpType;

uses(RefreshDatabase::class);

// Measured on the paired Mac and Galaxy A51. Both seeded their own accounts
// before pairing, so account 4 is a different bank on each. The phone's saved
// report filters on account 4 and the Mac stores that number verbatim, so one
// named report shows two figures and neither device says anything.

// The ids the alias map rewrites live inside one opaque text column here, and
// no foreign key reaches into a JSON value.
const JSONREF_PEER_DEVICE = 'the-phone-that-seeded-first';

function jsonRefUser(): User
{
    return User::query()->create([
        'username' => 'jsonref-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

/** @return array<string, mixed> */
function jsonRefAccountRow(string $slug, string $name, string $iban, string $writtenAt): array
{
    return [
        'name' => $name,
        'slug' => $slug,
        'kind' => 'bank',
        'iban' => $iban,
        'default_currency' => 'EUR',
        'created_at' => $writtenAt,
        'updated_at' => $writtenAt,
    ];
}

function jsonRefWriter(int $userId): OpLogWriter
{
    /** @var OpLogWriter $writer */
    $writer = app(OpLogWriter::class, [
        'deviceId' => JSONREF_PEER_DEVICE,
        'userId' => $userId,
        'secretKey' => sodium_crypto_sign_secretkey(test()->peerKeypair),
        'publicKey' => sodium_crypto_sign_publickey(test()->peerKeypair),
    ]);

    return $writer;
}

/** @return list<OpLogEntry> */
function jsonRefOps(DatabaseManager $db, int $userId): array
{
    return $db->connection()->table('op_log_entries')
        ->where('user_id', $userId)
        ->orderBy('id')
        ->get()
        ->map(static fn (object $row): OpLogEntry => new OpLogEntry(
            table: (string) $row->table_name,
            pk: is_numeric($row->pk) ? (int) $row->pk : (string) $row->pk,
            field: (string) $row->field,
            value: $row->value !== null ? (string) $row->value : null,
            hlcL: (int) $row->hlc_l,
            hlcC: (int) $row->hlc_c,
            deviceId: (string) $row->device_id,
            opType: OpType::from((string) $row->op_type),
            signature: (string) $row->signature,
            userId: (int) $row->user_id,
        ))
        ->all();
}

function jsonRefReplay(DatabaseManager $db, int $userId): void
{
    $keys = [JSONREF_PEER_DEVICE => bin2hex(sodium_crypto_sign_publickey(test()->peerKeypair))];

    (new OpLogReplayer($db, $keys, new MergeRulesRegistry))->replay(jsonRefOps($db, $userId), $userId);
}

// The account the peer's ids have to be rewritten to: it arrives under the id
// this device already gave a different bank, so it is stored under a fresh one.
function jsonRefRehomedAccountId(DatabaseManager $db, int $userId): int
{
    $id = $db->connection()->table('accounts')
        ->where('user_id', $userId)
        ->where('slug', 'jsonref-peer-bank')
        ->value('id');

    return is_numeric($id) ? (int) $id : 0;
}

/** @return array<string, mixed> */
function jsonRefStoredDefinition(DatabaseManager $db, int|string $reportId): array
{
    $stored = $db->connection()->table('saved_reports')->where('id', $reportId)->value('definition');
    $decoded = is_string($stored) ? json_decode($stored, true) : null;

    return is_array($decoded) ? $decoded : [];
}

/** @return array<string, mixed> */
function jsonRefReportDefinition(int $accountId, int $categoryId): array
{
    return [
        'metric' => 'spend',
        'dimension' => 'category',
        'periodPreset' => 'ytd',
        'granularity' => 'monthly',
        'currencyMode' => 'base',
        'viz' => 'table',
        'customFrom' => null,
        'customTo' => null,
        'compare' => false,
        'accounts' => [$accountId],
        'categories' => [$categoryId],
        'counterparties' => [],
        'amountMin' => null,
        'amountMax' => null,
        'amountDirection' => 'both',
    ];
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-09-13 09:15:00');

    $this->user = jsonRefUser();
    $this->peerKeypair = sodium_crypto_sign_keypair();

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $this->db = $db;

    $userId = (int) $this->user->id;

    // What this device seeded before pairing. The peer took the same number
    // for a bank of its own.
    $this->localAccountId = (int) $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        ...jsonRefAccountRow('jsonref-local-bank', 'ASN betaalrekening', 'NL00LOCL0000000001', '2026-01-04 08:30:00'),
    ]);

    jsonRefWriter($userId)->writeCreateRow(
        'accounts',
        $this->localAccountId,
        jsonRefAccountRow('jsonref-peer-bank', 'Bunq spaarrekening', 'NL00PEER0000000002', '2026-03-05 11:20:00'),
    );
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('re-homes the account the report filters on, which is what makes the id wrong', function (): void {
    $userId = (int) $this->user->id;

    jsonRefReplay($this->db, $userId);

    expect(jsonRefRehomedAccountId($this->db, $userId))
        ->toBeGreaterThan(0, 'the peer account was not stored at all, so nothing below is about a translated id')
        ->not->toBe($this->localAccountId, 'the peer account kept the id this device had given another bank');
});

it('stores the account filter of an arriving report under the id this device uses', function (): void {
    $userId = (int) $this->user->id;

    jsonRefWriter($userId)->writeCreateRow('saved_reports', 77001, [
        'name' => 'Waar het geld heen ging',
        'definition' => jsonRefReportDefinition($this->localAccountId, 4242),
        'pinned' => false,
    ]);

    jsonRefReplay($this->db, $userId);

    $rehomed = jsonRefRehomedAccountId($this->db, $userId);

    expect(jsonRefStoredDefinition($this->db, 77001)['accounts'] ?? null)->toBe(
        [$rehomed],
        'The report filters on the id the peer minted, which names a different bank here, so the same named report counts different money on the two devices.',
    );
});

// The other call site: a Set rewrites the whole column after the row is here,
// and it names ids the same way a create does.
it('stores the account filter of an arriving edit under the id this device uses', function (): void {
    $userId = (int) $this->user->id;

    jsonRefWriter($userId)->writeCreateRow('saved_reports', 77002, [
        'name' => 'Maandelijkse nettopositie',
        'definition' => jsonRefReportDefinition(4242, 4242),
        'pinned' => false,
    ]);

    jsonRefReplay($this->db, $userId);

    jsonRefWriter($userId)->writeSet('saved_reports', 77002, 'definition', jsonRefReportDefinition($this->localAccountId, 4242));

    jsonRefReplay($this->db, $userId);

    $rehomed = jsonRefRehomedAccountId($this->db, $userId);

    expect(jsonRefStoredDefinition($this->db, 77002)['accounts'] ?? null)->toBe([$rehomed]);
});

// The whole value is the list here — there is no key naming the table, which
// is the second shape the declaration has to be able to say.
it('stores an arriving calendar preference under the ids this device uses', function (): void {
    $userId = (int) $this->user->id;

    jsonRefWriter($userId)->writeCreateRow('user_preferences', 77003, [
        'counterparty_index_view' => 'cards',
        'reports_index_view' => 'cards',
        'skipped_update_versions' => '[]',
        'calendar_entries_accounts' => json_encode([$this->localAccountId], JSON_THROW_ON_ERROR),
        'calendar_balance_accounts' => json_encode([$this->localAccountId], JSON_THROW_ON_ERROR),
        'created_at' => '2026-02-01 00:00:00',
        'updated_at' => '2026-02-01 00:00:00',
    ]);

    jsonRefReplay($this->db, $userId);

    $rehomed = jsonRefRehomedAccountId($this->db, $userId);
    $stored = $this->db->connection()->table('user_preferences')->where('user_id', $userId)->first();

    expect(json_decode((string) ($stored->calendar_entries_accounts ?? ''), true))->toBe([$rehomed])
        ->and(json_decode((string) ($stored->calendar_balance_accounts ?? ''), true))->toBe([$rehomed]);
});

// Without this the rewrite could pass by replacing every id it finds.
it('leaves an id no alias names exactly as the peer wrote it', function (): void {
    $userId = (int) $this->user->id;

    jsonRefWriter($userId)->writeCreateRow('saved_reports', 77004, [
        'name' => 'Top tegenpartijen',
        'definition' => jsonRefReportDefinition(918273, 4242),
        'pinned' => false,
    ]);

    jsonRefReplay($this->db, $userId);

    expect(jsonRefStoredDefinition($this->db, 77004)['accounts'] ?? null)->toBe([918273]);
});
