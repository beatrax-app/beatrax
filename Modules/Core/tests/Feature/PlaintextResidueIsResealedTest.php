<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Internal\Encryption\PlaintextResidueSweep;
use Modules\Core\Internal\Encryption\PreMigrationSnapshot;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Core\Public\Services\SealedLedgerRecovery;
use Modules\Sync\Public\Exceptions\SensitiveColumnKeyUnavailableException;
use Modules\Sync\Public\Services\SensitiveColumnCodec;

uses(RefreshDatabase::class);

// The live desktop's own residue: six notifications written by
// EmitBudgetNudgesJob on the queue worker at 16:00, fifteen minutes after the
// enable-time sweep sealed the thirteen rows that already existed at 15:44.
/**
 * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md#getting-back-inside-the-guarantee
 */
const RESIDUE_TITLE = 'Budget nearly spent';

const RESIDUE_TRIGGER = 'budget_nudge';

function residueUser(): User
{
    return User::query()->create([
        'username' => 'residue-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

function residueEnroll(User $user): Session
{
    /** @var Session $session */
    $session = app(Session::class);
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));
    app(EncryptionMigrationService::class)->migrate($user, $session);

    return $session;
}

// A queue worker holds no HTTP session, so this is the state every background
// writer runs in on a device whose ledger is sealed.
function residueWriteInTheClear(User $user, string $id): void
{
    app(DatabaseManager::class)->connection()->table('notifications')->insert([
        'id' => $id,
        'user_id' => $user->id,
        'state' => 'open',
        'title' => RESIDUE_TITLE,
        'body' => 'Groceries is at 92% with 9 days to go.',
        'params' => json_encode(['budget' => 'Groceries'], JSON_THROW_ON_ERROR),
        'trigger_type' => RESIDUE_TRIGGER,
        'created_at' => '2026-08-22 16:00:09',
        'updated_at' => '2026-08-22 16:00:09',
    ]);
}

// What the schema migration leaves on an install that enabled encryption under
// the code that wrote the residue: the epoch pointer set, the coverage digest
// never stamped. Residue and a stamped digest cannot honestly coexist, because
// the pass that stamps it is the pass that would have sealed the row.
function residuePredatesTheMarker(User $user): void
{
    app(DatabaseManager::class)->connection()
        ->table('sync_encryption_state')
        ->where('user_id', $user->id)
        ->update(['resealed_columns_digest' => null]);
}

function residueRow(User $user, string $id): stdClass
{
    /** @var stdClass $row */
    $row = app(DatabaseManager::class)->connection()
        ->table('notifications')
        ->where('id', $id)
        ->first();

    return $row;
}

// Without this the fixture below is only an assertion about a hand-written
// row. It is the proof that the door those six rows came through is shut, so
// what remains really is history rather than a leak still in progress.
it('refuses a background write into a registered column now, which is why the residue is finite', function (): void {
    $user = residueUser();
    residueEnroll($user);

    /** @var Session $worker */
    $worker = app(Session::class);
    AppLockTestHarness::lock($worker);

    /** @var SensitiveColumnCodec $codec */
    $codec = app(SensitiveColumnCodec::class);

    expect(fn () => $codec->encryptAttrs(
        'notifications',
        ['title' => RESIDUE_TITLE, 'trigger_type' => RESIDUE_TRIGGER],
        (int) $user->id,
        $worker,
    ))->toThrow(SensitiveColumnKeyUnavailableException::class);
});

it('seals a row a locked background writer left in the clear, on the next unlocked request', function (): void {
    $user = residueUser();
    $session = residueEnroll($user);
    residueWriteInTheClear($user, str_repeat('b', 64));
    residuePredatesTheMarker($user);

    expect(residueRow($user, str_repeat('b', 64))->title)->toBe(RESIDUE_TITLE);

    $this->actingAs($user)->get('/notifications')->assertOk();

    $row = residueRow($user, str_repeat('b', 64));
    expect($row->title)->not->toBe(RESIDUE_TITLE);
    expect($row->trigger_type)->not->toBe(RESIDUE_TRIGGER);

    /** @var SensitiveColumnCodec $codec */
    $codec = app(SensitiveColumnCodec::class);
    expect($codec->decryptValue('notifications', 'title', (string) $row->title, (int) $user->id, $session))
        ->toBe(['value' => RESIDUE_TITLE, 'decrypted' => true]);
    expect($codec->decryptValue('notifications', 'trigger_type', (string) $row->trigger_type, (int) $user->id, $session))
        ->toBe(['value' => RESIDUE_TRIGGER, 'decrypted' => true]);
});

// The pass has to be safe to reach twice, because the marker is stamped after
// the work and a crash between them replays it.
it('leaves an already sealed value byte-identical on a second pass', function (): void {
    $user = residueUser();
    $session = residueEnroll($user);
    residueWriteInTheClear($user, str_repeat('c', 64));
    residuePredatesTheMarker($user);

    /** @var SealedLedgerRecovery $recovery */
    $recovery = app(SealedLedgerRecovery::class);
    $recovery->recover((int) $user->id, $session);
    $sealedOnce = (string) residueRow($user, str_repeat('c', 64))->title;

    residuePredatesTheMarker($user);
    $recovery->recover((int) $user->id, $session);

    expect((string) residueRow($user, str_repeat('c', 64))->title)->toBe($sealedOnce);
});

// The guard the whole design turns on: a pass that runs where no key is
// reachable can only make things worse, and this one does nothing at all.
it('does not touch the row when the session holds no key', function (): void {
    $user = residueUser();
    residueEnroll($user);
    residueWriteInTheClear($user, str_repeat('d', 64));
    residuePredatesTheMarker($user);

    /** @var Session $worker */
    $worker = app(Session::class);
    AppLockTestHarness::lock($worker);

    app(SealedLedgerRecovery::class)->recover((int) $user->id, $worker);

    expect(residueRow($user, str_repeat('d', 64))->title)->toBe(RESIDUE_TITLE);
});

// A pass that swept shows up here whatever it found, because the sweep reads
// every table it covers. The gate in front of it is the subject: an install
// with nothing to do must not be reading rows.
/**
 * @return list<string>
 */
function residueQueriesAgainstSweptTables(Closure $pass): array
{
    $seen = [];
    $tables = array_keys(PreMigrationSnapshot::PROJECTION_COLUMNS);

    app(DatabaseManager::class)->listen(function (QueryExecuted $query) use (&$seen, $tables): void {
        foreach ($tables as $table) {
            if (str_contains($query->sql, '"'.$table.'"')) {
                $seen[] = $query->sql;
            }
        }
    });

    $pass();

    return $seen;
}

// The defect this file was extended for. The enable-time pass stamps the
// coverage digest, so on a normally enrolled install the digest gate is equal
// from that moment and never reopens — a writer that goes around the codec
// afterwards leaves a value no later pass ever looks at.
/**
 * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md#a-digest-that-was-asked-to-answer-two-questions
 */
it('sweeps again on its own, with no registry change to announce the residue', function (): void {
    $user = residueUser();
    $session = residueEnroll($user);
    residueWriteInTheClear($user, str_repeat('e', 64));

    /** @var SealedLedgerRecovery $recovery */
    $recovery = app(SealedLedgerRecovery::class);
    $recovery->recover((int) $user->id, $session);
    $recovery->recover((int) $user->id, $session);

    $this->travel(PlaintextResidueSweep::RESWEEP_AFTER_HOURS + 1)->hours();
    $recovery->recover((int) $user->id, $session);

    $row = residueRow($user, str_repeat('e', 64));
    expect($row->title)->not->toBe(RESIDUE_TITLE);

    /** @var SensitiveColumnCodec $codec */
    $codec = app(SensitiveColumnCodec::class);
    expect($codec->decryptValue('notifications', 'title', (string) $row->title, (int) $user->id, $session))
        ->toBe(['value' => RESIDUE_TITLE, 'decrypted' => true]);
});

// The other half of the same change, and it has to pass before it as well as
// after: closing a silent gap by making every request scan the ledger would be
// a silent cost in its place.
it('reads no row of a swept table on a clean enrolled install', function (): void {
    $user = residueUser();
    $session = residueEnroll($user);

    /** @var SealedLedgerRecovery $recovery */
    $recovery = app(SealedLedgerRecovery::class);

    $seen = residueQueriesAgainstSweptTables(function () use ($recovery, $user, $session): void {
        $recovery->recover((int) $user->id, $session);
        $recovery->recover((int) $user->id, $session);
    });

    expect($seen)->toBe([]);
});

// A pass that swept and did not stamp would sweep again on the next request,
// which is the recurring full scan the digest gate was built to stop.
it('stamps the pass it just ran, so the window opens once and not per request', function (): void {
    $user = residueUser();
    $session = residueEnroll($user);
    residueWriteInTheClear($user, str_repeat('f', 64));

    /** @var SealedLedgerRecovery $recovery */
    $recovery = app(SealedLedgerRecovery::class);

    $this->travel(PlaintextResidueSweep::RESWEEP_AFTER_HOURS + 1)->hours();
    $recovery->recover((int) $user->id, $session);

    $seen = residueQueriesAgainstSweptTables(function () use ($recovery, $user, $session): void {
        $recovery->recover((int) $user->id, $session);
    });

    expect($seen)->toBe([]);
});
