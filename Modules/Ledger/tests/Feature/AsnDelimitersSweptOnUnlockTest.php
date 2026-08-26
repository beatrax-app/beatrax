<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Ingestion\Public\Enums\SourceFormat;
use Modules\Ledger\Internal\Services\StripAsnDescriptionDelimiters;
use Modules\Ledger\Models\Account;
use Modules\Search\Public\Contracts\SearchIndexWriterContract;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Sync\Tests\Support\EnablesEncryptionForUser;

uses(EnablesEncryptionForUser::class);

// Read off the desktop install the migration had already run on: every asn-csv
// row belonged to a sealed ledger, the pass skipped all of them, and the
// migration was recorded as Ran with no retry left.
const ASNU_WRAPPED = "'ROUND6 ALPHA INVOICE 1'";

const ASNU_UNWRAPPED = 'ROUND6 ALPHA INVOICE 1';

// The passphrase EnablesEncryptionForUser wraps the keyring under, spelled out
// so a test can lock and then re-open the SAME epoch rather than mint a second.
function asnuKek(): string
{
    return str_repeat('*', 32);
}

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'asn-unlock-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    $this->account = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'ASN betaalrekening',
        'slug' => 'asn-betaalrekening',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);

    $this->importRun = $this->makeImportRun($this->user, str_repeat('f', 64));

    $this->seedRow = function (string $description, string $sourceFormat = SourceFormat::AsnCsv->value): int {
        $transaction = $this->makeTransaction($this->user, $this->account, $this->importRun, [
            'description' => $description,
            'source_format' => $sourceFormat,
        ]);

        app(SearchIndexWriterContract::class)->upsertForTransaction((int) $transaction->id, (int) $this->user->id);

        return (int) $transaction->id;
    };

    $this->storedDescription = fn (int $id): ?string => $this->app->make(DatabaseManager::class)
        ->connection()
        ->table('transactions')
        ->where('id', $id)
        ->value('description');

    $this->searchBody = fn (int $id): ?string => $this->app->make(DatabaseManager::class)
        ->connection()
        ->table('transaction_search_docs')
        ->where('transaction_id', $id)
        ->value('search_body');

    $this->markers = fn (): int => $this->app->make(DatabaseManager::class)
        ->connection()
        ->table('ledger_backfill_state')
        ->where('user_id', (int) $this->user->id)
        ->where('backfill', 'asn-description-delimiters')
        ->count();

    $this->sealTheRow = function (): array {
        $session = $this->enablesEncryptionForUser($this->user);

        /** @var SensitiveColumnCodec $codec */
        $codec = $this->app->make(SensitiveColumnCodec::class);
        $sealed = $codec->encryptValue('transactions', 'description', ASNU_WRAPPED, (int) $this->user->id, $session);

        $id = ($this->seedRow)(ASNU_WRAPPED);
        $this->app->make(DatabaseManager::class)->connection()
            ->table('transactions')->where('id', $id)->update(['description' => $sealed]);

        AppLockTestHarness::lock($session);

        return [$id, $sealed, $session, $codec];
    };

    // Exactly what OpLogEntryApplier::insertCreatedRow does for a row a paired
    // device sends: a raw insert under the ORIGINATING device's id, with no
    // model event and so no op-log entry of its own, followed by the same
    // search-document refresh the replayer runs.
    $this->arriveFromPeer = function (int $id, string $description) {
        $connection = $this->app->make(DatabaseManager::class)->connection();

        /** @var array<string, mixed> $row */
        $row = (array) $connection->table('transactions')->orderBy('id')->first();
        $row['id'] = $id;
        $row['description'] = $description;
        $row['fingerprint'] = str_pad((string) $id, 64, 'a', STR_PAD_LEFT);

        // transactions carries a natural-key unique index, so a clone has to
        // differ on it or the insert the applier makes would be refused.
        $row['counterparty_name'] = "Peer merchant {$id}";
        $row['counterparty_normalized'] = "peer merchant {$id}";
        $row['amount_minor'] = -$id;
        $row['settled_amount_minor'] = -$id;

        $connection->table('transactions')->insert($row);
        app(SearchIndexWriterContract::class)->upsertForTransaction($id, (int) $this->user->id);

        return $id;
    };

    $this->highestAsnId = fn (): int => (int) $this->app->make(DatabaseManager::class)
        ->connection()
        ->table('transactions')
        ->where('user_id', (int) $this->user->id)
        ->where('source_format', SourceFormat::AsnCsv->value)
        ->max('id');

    $this->queriesDuring = function (callable $work): array {
        $seen = [];
        $connection = $this->app->make(DatabaseManager::class)->connection();
        $connection->listen(static function (object $query) use (&$seen): void {
            $seen[] = (string) $query->sql;
        });

        $work();

        return $seen;
    };
});

it('leaves a sealed ledger the migration pass cannot open untouched, and records nothing', function (): void {
    [$id, $sealed] = ($this->sealTheRow)();

    expect($this->app->make(StripAsnDescriptionDelimiters::class)->run())->toBe(0)
        ->and(($this->storedDescription)($id))->toBe($sealed)
        ->and(($this->markers)())->toBe(0);
});

it('sweeps that same ledger on the unlock that makes the key available', function (): void {
    [$id, $sealed, $session, $codec] = ($this->sealTheRow)();

    expect($this->app->make(StripAsnDescriptionDelimiters::class)->run())->toBe(0);

    $this->actingAs($this->user);
    AppLockTestHarness::unlock($session, asnuKek());

    $after = (string) ($this->storedDescription)($id);

    expect($after)->not->toBe($sealed)
        ->and($after)->not->toBe(ASNU_UNWRAPPED)
        ->and($codec->decryptValue('transactions', 'description', $after, (int) $this->user->id, $session)['value'])
        ->toBe(ASNU_UNWRAPPED)
        ->and(($this->markers)())->toBe(1);
});

it('leaves the swept ledger alone on every unlock after it, without reading the ledger', function (): void {
    [$id, , $session] = ($this->sealTheRow)();

    $this->actingAs($this->user);
    AppLockTestHarness::unlock($session, asnuKek());
    $afterFirst = ($this->storedDescription)($id);

    AppLockTestHarness::lock($session);
    $queries = ($this->queriesDuring)(function () use ($session): void {
        AppLockTestHarness::unlock($session, asnuKek());
    });

    $ledgerReads = array_values(array_filter($queries, static fn (string $sql): bool => str_contains($sql, '"transactions"')));

    expect(($this->storedDescription)($id))->toBe($afterFirst)
        ->and($ledgerReads)->toHaveCount(1)
        ->and($ledgerReads[0])->toContain('COUNT(*)')
        ->and(implode(' ', $queries))->not->toContain('"description"')
        ->and(implode(' ', $queries))->not->toContain('update');
});

it('costs one marker read on a user who never imported an ASN file', function (): void {
    ($this->seedRow)('Maandelijkse bijdrage', 'camt053');

    $this->actingAs($this->user);
    /** @var Session $session */
    $session = $this->app->make(Session::class);

    AppLockTestHarness::unlock($session, asnuKek());

    expect(($this->markers)())->toBe(1);

    AppLockTestHarness::lock($session);
    $queries = ($this->queriesDuring)(function () use ($session): void {
        AppLockTestHarness::unlock($session, asnuKek());
    });

    $ledgerReads = array_values(array_filter($queries, static fn (string $sql): bool => str_contains($sql, '"transactions"')));

    expect($ledgerReads)->toHaveCount(1)
        ->and($ledgerReads[0])->toContain('COUNT(*)')
        ->and(array_values(array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'ledger_backfill_state'))))
        ->toHaveCount(1)
        ->and(implode(' ', $queries))->not->toContain('"description"');
});

it('rewrites the search document alongside the ledger row it swept on unlock', function (): void {
    $id = ($this->seedRow)(ASNU_WRAPPED);

    expect(($this->searchBody)($id))->toContain(ASNU_WRAPPED);

    $this->actingAs($this->user);
    /** @var Session $session */
    $session = $this->app->make(Session::class);
    AppLockTestHarness::unlock($session, asnuKek());

    expect(($this->storedDescription)($id))->toBe(ASNU_UNWRAPPED)
        ->and(($this->searchBody)($id))->toContain(ASNU_UNWRAPPED)
        ->and(($this->searchBody)($id))->not->toContain(ASNU_WRAPPED);
});

it('does not turn a failed pass into an exception on the lock screen', function (): void {
    $id = ($this->seedRow)(ASNU_WRAPPED);

    $this->actingAs($this->user);
    $this->app->bind(StripAsnDescriptionDelimiters::class, static function (): StripAsnDescriptionDelimiters {
        throw new RuntimeException('the sweep could not start');
    });

    /** @var Session $session */
    $session = $this->app->make(Session::class);
    AppLockTestHarness::unlock($session, asnuKek());

    expect(AppLockTestHarness::isLocked($session))->toBeFalse()
        ->and(($this->storedDescription)($id))->toBe(ASNU_WRAPPED)
        ->and(($this->markers)())->toBe(0);
});

// The sync daemon runs independently of the lock screen, so a peer that is
// still locked — and has therefore never swept its own copy — can replicate
// pre-fix rows into a device that already finished. OpLogEntryApplier inserts
// them under the SENDING device's id, which is minted there and owes nothing
// to this device's sequence, so an arrival routinely lands below everything
// already swept. A highest-id watermark would step straight over it.
it('sweeps a row that arrived from a peer below every id already swept', function (): void {
    ($this->seedRow)(ASNU_WRAPPED);
    ($this->arriveFromPeer)(5000, ASNU_WRAPPED);

    $this->actingAs($this->user);
    /** @var Session $session */
    $session = $this->app->make(Session::class);
    AppLockTestHarness::unlock($session, asnuKek());

    expect(($this->highestAsnId)())->toBe(5000)
        ->and(($this->markers)())->toBe(1);

    $arrived = ($this->arriveFromPeer)(7, ASNU_WRAPPED);

    expect($arrived)->toBeLessThan(($this->highestAsnId)())
        ->and(($this->storedDescription)($arrived))->toBe(ASNU_WRAPPED);

    AppLockTestHarness::lock($session);
    AppLockTestHarness::unlock($session, asnuKek());

    expect(($this->storedDescription)($arrived))->toBe(ASNU_UNWRAPPED)
        ->and(($this->searchBody)($arrived))->toContain(ASNU_UNWRAPPED)
        ->and(($this->searchBody)($arrived))->not->toContain(ASNU_WRAPPED);
});

it('settles again once the arrival has been swept', function (): void {
    ($this->seedRow)(ASNU_WRAPPED);

    $this->actingAs($this->user);
    /** @var Session $session */
    $session = $this->app->make(Session::class);
    AppLockTestHarness::unlock($session, asnuKek());

    $arrived = ($this->arriveFromPeer)(4000, ASNU_WRAPPED);

    AppLockTestHarness::lock($session);
    AppLockTestHarness::unlock($session, asnuKek());
    AppLockTestHarness::lock($session);

    $queries = ($this->queriesDuring)(function () use ($session): void {
        AppLockTestHarness::unlock($session, asnuKek());
    });

    $ledgerReads = array_values(array_filter($queries, static fn (string $sql): bool => str_contains($sql, '"transactions"')));

    expect(($this->storedDescription)($arrived))->toBe(ASNU_UNWRAPPED)
        ->and($ledgerReads)->toHaveCount(1)
        ->and($ledgerReads[0])->toContain('COUNT(*)')
        ->and(implode(' ', $queries))->not->toContain('"description"');
});
