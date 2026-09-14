<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\OpLog\QuarantineReason;
use Modules\Sync\Public\Services\HistoryReprojector;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Sync\Public\Services\StrandedCreateHealthCheck;

uses(RefreshDatabase::class);

// Measured on a paired Mac and Galaxy A51, 2026-09-13: nineteen counterparty
// ids carry a create from both devices under a different slug, and fifteen of
// the phone's slugs are in no row here. No alias, no quarantine row, and the
// catch-up watermark is past every one of them, so nothing ever asks again.
const STRANDED_REPAIR_PEER = 'the-phone-that-classified-first';

const STRANDED_REPAIR_SELF = 'the-desktop-that-kept-the-row';

// The fifteen, by the slug each is found again by. Spelled out rather than
// generated because the shape is the measurement: three devices' worth of
// slug-collision suffixes is what a phone minting beside a desktop produces.
const STRANDED_REPAIR_SLUGS = [
    'kpn-mobiel', 'kpn-mobiel-2', 'kpn-mobiel-3', 'netflix', 'jumbo-supermarkten',
    'bunq-b-v', 'shell', 'netflix-2', 'anwb-wegenwacht', 'cafe-plein',
    'netflix-3', 'tikkie-payments', 'kpn-mobiel-4', 'albert-heijn-2', 'koffiehuis',
];

function strandedRepairUser(): User
{
    return User::query()->create([
        'username' => 'strandedrepair-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

// The birth time is the caller's because it is load-bearing: `CreateRowCollision`
// calls a create a different row only when created_at disagrees AND some other
// column does. The measured pair disagree -- the phone classified at 19:06 and
// the desktop at 21:10 -- and equal ones read as the same create arriving again.
/** @return array<string, mixed> */
function strandedRepairRow(string $slug, string $displayName, string $writtenAt, ?string $iban = null): array
{
    return [
        'type' => 'merchant',
        'slug' => $slug,
        'display_name' => $displayName,
        'merchant_name' => $displayName,
        'iban' => $iban,
        'metadata' => null,
        'created_at' => $writtenAt,
        'updated_at' => $writtenAt,
    ];
}

function strandedRepairPeerWrote(): string
{
    return '2026-09-10 19:06:44';
}

function strandedRepairDesktopWrote(): string
{
    return '2026-09-10 21:10:03';
}

function strandedRepairWriter(int $userId, string $deviceId, string $keypair): OpLogWriter
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

function strandedRepairRegister(DatabaseManager $db, int $userId, string $deviceId, string $keypair, bool $isSelf): void
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

/** @return list<string> */
function strandedRepairSlugsHere(DatabaseManager $db, int $userId): array
{
    /** @var list<string> $slugs */
    $slugs = $db->connection()->table('counterparties')->where('user_id', $userId)->orderBy('slug')->pluck('slug')->all();

    return $slugs;
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-09-13 16:00:00');

    $this->user = strandedRepairUser();
    $this->userId = (int) $this->user->id;
    $this->peerKeypair = sodium_crypto_sign_keypair();
    $this->selfKeypair = sodium_crypto_sign_keypair();

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $this->db = $db;

    strandedRepairRegister($db, $this->userId, STRANDED_REPAIR_PEER, $this->peerKeypair, false);
    strandedRepairRegister($db, $this->userId, STRANDED_REPAIR_SELF, $this->selfKeypair, true);

    // The local rows the peer's ids collide with: this device classified its
    // own import first, so ids 1..15 are taken by counterparties the peer never
    // sent. Each carries this device's own create, which is what makes the id
    // contested rather than merely occupied -- the shape a pk check cannot see.
    $this->localIds = [];
    $self = strandedRepairWriter($this->userId, STRANDED_REPAIR_SELF, $this->selfKeypair);

    foreach (range(1, count(STRANDED_REPAIR_SLUGS)) as $n) {
        $localRow = strandedRepairRow('local-'.$n, 'Local '.$n, strandedRepairDesktopWrote());
        $localId = (int) $db->connection()->table('counterparties')->insertGetId([
            'user_id' => $this->userId,
            ...$localRow,
        ]);

        $self->writeCreateRow('counterparties', $localId, $localRow);
        $this->localIds[] = $localId;
    }

    // Written to the log and never applied, which IS the stranded state: the
    // create is on disk, no row answers for it, and nothing is owed anywhere.
    $this->strand = function (?array $slugs = null) use ($db): void {
        $writer = strandedRepairWriter($this->userId, STRANDED_REPAIR_PEER, $this->peerKeypair);

        foreach ($slugs ?? STRANDED_REPAIR_SLUGS as $index => $slug) {
            $writer->writeCreateRow('counterparties', $this->localIds[$index], strandedRepairRow($slug, ucfirst($slug), strandedRepairPeerWrote(), 'NL91ABNA041716430'.$index));
        }

        unset($db);
    };
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('names every create no row here answers for and changes nothing without --apply', function (): void {
    ($this->strand)();

    $this->artisan('sync:repair-stranded-creates', ['--user' => (string) $this->userId])
        ->expectsOutputToContain('15 create(s) the log holds')
        ->expectsOutputToContain('slug=kpn-mobiel')
        ->expectsOutputToContain('slug=koffiehuis')
        ->expectsOutputToContain('Dry run: nothing was changed.')
        ->assertExitCode(0);

    expect($this->db->connection()->table('counterparties')->where('user_id', $this->userId)->count())->toBe(15)
        ->and($this->db->connection()->table('op_log_row_aliases')->count())->toBe(0);
});

it('places fifteen rows under ids this device mints and then has nothing left to place', function (): void {
    ($this->strand)();

    $this->artisan('sync:repair-stranded-creates', ['--user' => (string) $this->userId, '--apply' => true])
        ->expectsOutputToContain('15 row(s) placed in counterparties')
        ->assertExitCode(0);

    expect($this->db->connection()->table('counterparties')->where('user_id', $this->userId)->count())->toBe(30)
        ->and(array_values(array_intersect(strandedRepairSlugsHere($this->db, $this->userId), STRANDED_REPAIR_SLUGS)))
        ->toEqualCanonicalizing(STRANDED_REPAIR_SLUGS);

    $this->artisan('sync:repair-stranded-creates', ['--user' => (string) $this->userId, '--apply' => true])
        ->expectsOutputToContain('0 create(s) the log holds')
        ->expectsOutputToContain('Nothing to repair.')
        ->assertExitCode(0);

    // The row `beatrax:doctor` prints, asked of the check that prints it: the
    // warning is only ever the creates a repair could still place, so reaching
    // ok is the same statement as the second run having nothing left to do.
    /** @var StrandedCreateHealthCheck $health */
    $health = $this->app->make(StrandedCreateHealthCheck::class);

    expect($this->db->connection()->table('counterparties')->where('user_id', $this->userId)->count())->toBe(30)
        ->and($health->severity())->toBe('ok')
        ->and($health->message())->toContain('all of them here');
});

// The id the peer used has to keep meaning something, or every transaction it
// sends naming that counterparty lands on the local row it collided with.
it('aliases the id the peer used to the id this device gave the row', function (): void {
    ($this->strand)(['kpn-mobiel']);

    $this->artisan('sync:repair-stranded-creates', ['--user' => (string) $this->userId, '--apply' => true])->assertExitCode(0);

    $rehomedId = $this->db->connection()->table('counterparties')->where('slug', 'kpn-mobiel')->value('id');

    expect($this->db->connection()->table('op_log_row_aliases')
        ->where('user_id', $this->userId)
        ->where('table_name', 'counterparties')
        ->where('device_id', STRANDED_REPAIR_PEER)
        ->where('remote_id', (string) $this->localIds[0])
        ->value('local_id'))->toBe((string) $rehomedId);
});

it('reports and skips a create no natural key can identify', function (): void {
    $row = strandedRepairRow('unused', 'No Slug Here', strandedRepairPeerWrote());
    unset($row['slug']);

    strandedRepairWriter($this->userId, STRANDED_REPAIR_PEER, $this->peerKeypair)
        ->writeCreateRow('counterparties', 90210, $row);

    $this->artisan('sync:repair-stranded-creates', ['--user' => (string) $this->userId, '--apply' => true])
        ->expectsOutputToContain('no natural key — skipped')
        ->assertExitCode(0);

    expect($this->db->connection()->table('counterparties')->where('user_id', $this->userId)->count())->toBe(15)
        ->and($this->db->connection()->table('op_log_quarantine')->count())->toBe(0);
});

// The two populations beside the fifteen on the measured database, reproduced
// by their CAUSE rather than by their table name: a rule keyed on a table is
// blind to the next one that legitimately loses a row.
it('cannot reach a create this device authored, nor one held under a verdict no later state undoes', function (): void {
    $gone = (int) $this->db->connection()->table('counterparties')->insertGetId([
        'user_id' => $this->userId,
        ...strandedRepairRow('written-here-and-removed', 'Written Here', strandedRepairDesktopWrote()),
    ]);

    strandedRepairWriter($this->userId, STRANDED_REPAIR_SELF, $this->selfKeypair)
        ->writeCreateRow('counterparties', $gone, strandedRepairRow('written-here-and-removed', 'Written Here', strandedRepairDesktopWrote()));

    $this->db->connection()->table('counterparties')->where('id', $gone)->delete();

    ($this->strand)(['held-terminally']);

    $this->db->connection()->table('op_log_quarantine')->insert([
        'user_id' => $this->userId,
        'table_name' => 'counterparties',
        'pk' => (string) $this->localIds[0],
        'device_id' => STRANDED_REPAIR_PEER,
        'op_type' => OpType::CreateRow->value,
        'reason' => QuarantineReason::UnplaceableCollision->value,
        'hlc_l' => 1,
        'hlc_c' => 0,
        'created_at' => '2026-09-13 15:00:00',
    ]);

    $this->artisan('sync:repair-stranded-creates', ['--user' => (string) $this->userId, '--apply' => true])
        ->expectsOutputToContain('0 create(s) the log holds')
        ->assertExitCode(0);

    expect(strandedRepairSlugsHere($this->db, $this->userId))->not->toContain('held-terminally')
        ->and(strandedRepairSlugsHere($this->db, $this->userId))->not->toContain('written-here-and-removed');
});

it('brings the peer\'s sealed name back readable when the key is held', function (): void {
    /** @var Session $session */
    $session = $this->app->make(Session::class);
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));
    $this->app->make(GdkKeyringService::class)->generateAndPersist($this->userId, $session);

    ($this->strand)(['kpn-mobiel']);

    $sealed = $this->db->connection()->table('op_log_entries')
        ->where('table_name', 'counterparties')->where('field', 'display_name')->value('value');

    expect(str_contains((string) $sealed, 'Kpn-mobiel'))->toBeFalse('The fixture stored the name in the clear, so nothing below is a test of sealing.');

    $this->artisan('sync:repair-stranded-creates', ['--user' => (string) $this->userId, '--apply' => true])
        ->expectsOutputToContain('1 row(s) placed in counterparties')
        ->assertExitCode(0);

    $stored = $this->db->connection()->table('counterparties')->where('slug', 'kpn-mobiel')->first();

    /** @var SensitiveColumnCodec $codec */
    $codec = $this->app->make(SensitiveColumnCodec::class);

    expect(is_object($stored) ? (string) $stored->display_name : '')->not->toBe('Kpn-mobiel')
        ->and($codec->decryptValue('counterparties', 'display_name', is_object($stored) ? (string) $stored->display_name : '', $this->userId, $session))
        ->toBe(['value' => 'Kpn-mobiel', 'decrypted' => true]);
});

// A row written here without the key holds the op log's ciphertext, which the
// column codec can never open: its associated data binds the pk, and the pk is
// the one thing a re-home changes. SplitCreateTail fills a column holding null,
// never one holding the wrong bytes, so nothing later would ever repair it.
it('writes no row at all when the key is out of reach, and records the coordinate the recovery pass needs', function (): void {
    /** @var Session $session */
    $session = $this->app->make(Session::class);
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));
    $this->app->make(GdkKeyringService::class)->generateAndPersist($this->userId, $session);

    ($this->strand)(['kpn-mobiel', 'netflix']);

    AppLockTestHarness::lock($session);

    $this->artisan('sync:repair-stranded-creates', ['--user' => (string) $this->userId, '--apply' => true])
        ->expectsOutputToContain('Nothing was written to counterparties')
        ->expectsOutputToContain('2 create(s) recorded as owed, 2 owed in total')
        ->expectsOutputToContain('Open the app with the lock off')
        ->assertExitCode(0);

    expect($this->db->connection()->table('counterparties')->where('user_id', $this->userId)->count())->toBe(15);

    $held = $this->db->connection()->table('op_log_quarantine')->where('user_id', $this->userId)->get();

    expect($held)->toHaveCount(2)
        ->and($held->pluck('reason')->unique()->all())->toBe([QuarantineReason::PrimaryKeyCollision->value]);

    // Asked twice on purpose: a second run that wrote two more holds would
    // grow the audit table every time an operator looked.
    $this->artisan('sync:repair-stranded-creates', ['--user' => (string) $this->userId, '--apply' => true])
        ->expectsOutputToContain('0 create(s) recorded as owed, 2 owed in total')
        ->assertExitCode(0);

    expect($this->db->connection()->table('op_log_quarantine')->where('user_id', $this->userId)->count())->toBe(2);
});

// The half that makes the branch above a repair rather than a note: the hold
// is the coordinate `RetriedCollisionCreates` reads, and with it the pass that
// DOES hold the key takes the create through the same applier and seals the
// name on the way in. Without this the keyless arm is a dead end nobody walks.
it('lets the sealed-ledger recovery pass place what the keyless run recorded', function (): void {
    /** @var Session $session */
    $session = $this->app->make(Session::class);
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));
    $this->app->make(GdkKeyringService::class)->generateAndPersist($this->userId, $session);

    ($this->strand)(['kpn-mobiel', 'netflix']);

    AppLockTestHarness::lock($session);
    $this->artisan('sync:repair-stranded-creates', ['--user' => (string) $this->userId, '--apply' => true])->assertExitCode(0);

    expect($this->db->connection()->table('counterparties')->where('user_id', $this->userId)->count())->toBe(15);

    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));

    /** @var HistoryReprojector $reprojector */
    $reprojector = $this->app->make(HistoryReprojector::class);
    $reprojector->replayQuarantined($this->userId, $session, null, null);

    $stored = $this->db->connection()->table('counterparties')->where('slug', 'kpn-mobiel')->first();

    /** @var SensitiveColumnCodec $codec */
    $codec = $this->app->make(SensitiveColumnCodec::class);

    expect($this->db->connection()->table('counterparties')->where('user_id', $this->userId)->count())->toBe(17)
        ->and(strandedRepairSlugsHere($this->db, $this->userId))->toContain('kpn-mobiel', 'netflix')
        ->and($codec->decryptValue('counterparties', 'display_name', is_object($stored) ? (string) $stored->display_name : '', $this->userId, $session))
        ->toBe(['value' => 'Kpn-mobiel', 'decrypted' => true])
        ->and($this->db->connection()->table('op_log_quarantine')->where('user_id', $this->userId)->count())
        ->toBe(0, 'The hold is spent once the row it named is here; leaving it would report a refusal for a row sitting right there.');

    // What the reader is handed, column by column. The only thing about a
    // recovered row that differs from one this device created is its id, and
    // nothing shows a reader an id -- the slug is what the URL carries.
    expect(is_object($stored) ? (string) $stored->type : '')->toBe('merchant')
        ->and(is_object($stored) ? (string) $stored->created_at : '')->toBe(
            strandedRepairPeerWrote(),
            'The day the peer wrote the row, not the day it was recovered.',
        )
        ->and(is_object($stored) ? (string) $stored->iban : '')->not->toBe('')
        ->and(is_object($stored) ? (int) $stored->id : 0)->toBeGreaterThan(
            max($this->localIds),
            'A fresh id this device minted, because the one the peer used is a row of this device already.',
        );
});
