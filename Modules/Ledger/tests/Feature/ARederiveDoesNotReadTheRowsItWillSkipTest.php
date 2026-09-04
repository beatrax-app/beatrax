<?php

declare(strict_types=1);

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Ledger\Internal\Services\FingerprintRederiveService;
use Modules\Ledger\Public\Services\FingerprintComposer;

// The re-derive runs as a migration, and a phone applies every migration. A
// whole-ledger read of twenty columns peaked at 52 MB over 25,000 rows against
// a 128 MB device ceiling, nearly all of it rows already at the target version.

const RDR_SETTLED_ROWS = 1000;

const RDR_DESCRIPTION_BYTES = 16384;

// Comfortably above the streamed cost and far below the 17 MB the same fixture
// cost when every row was materialised at once.
const RDR_PEAK_CEILING_BYTES = 4194304;

function rdrParents(ConnectionInterface $conn, int $userId): array
{
    $accountId = (int) $conn->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'RDR Bank',
        'slug' => 'rdr-bank',
        'kind' => 'bank',
        'iban' => 'NL00RDRB0000001',
        'default_currency' => 'EUR',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $runId = (int) $conn->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/rdr.csv',
        'sha256' => hash('sha256', 'rdr'),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [$accountId, $runId];
}

function rdrRow(int $userId, int $accountId, int $runId, int $index, int $version, string $description): array
{
    return [
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'rdr-'.$index),
        'fingerprint_version' => $version,
        'normalization_version' => $version,
        'posted_at' => '2026-03-01',
        'booked_at' => '2026-03-01 00:00:0'.($index % 10),
        'value_date' => '2026-03-01',
        'amount_minor' => -100 - $index,
        'currency' => 'EUR',
        'settled_amount_minor' => -100 - $index,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'rdr merchant '.$index,
        'counterparty_name' => 'RDR Merchant '.$index,
        'description' => $description,
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => $index,
        'status' => 'cleared',
        'payment_type' => 'pin',
        'created_at' => now(),
        'updated_at' => now(),
    ];
}

it('never materialises the rows already at the target version', function (): void {
    /** @var DatabaseManager $manager */
    $manager = app(DatabaseManager::class);
    $conn = $manager->connection();

    /** @var User $user */
    $user = User::query()->create([
        'username' => 'rdr-reader',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $userId = (int) $user->id;
    [$accountId, $runId] = rdrParents($conn, $userId);

    /** @var FingerprintComposer $composer */
    $composer = app(FingerprintComposer::class);
    $settled = $composer->version();
    $fat = str_repeat('x', RDR_DESCRIPTION_BYTES);

    foreach (array_chunk(range(1, RDR_SETTLED_ROWS), 50) as $chunk) {
        $conn->table('transactions')->insert(array_map(
            static fn (int $i): array => rdrRow($userId, $accountId, $runId, $i, $settled, $fat),
            $chunk,
        ));
    }

    /** @var FingerprintRederiveService $service */
    $service = app(FingerprintRederiveService::class);

    memory_reset_peak_usage();
    $before = memory_get_usage();
    $outcome = $service->run(apply: false);
    $grew = memory_get_peak_usage() - $before;

    expect($outcome->isCollision())->toBeFalse()
        ->and($grew)->toBeLessThan(RDR_PEAK_CEILING_BYTES);
});

it('still re-derives a row that is behind the target version', function (): void {
    /** @var DatabaseManager $manager */
    $manager = app(DatabaseManager::class);
    $conn = $manager->connection();

    /** @var User $user */
    $user = User::query()->create([
        'username' => 'rdr-behind',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $userId = (int) $user->id;
    [$accountId, $runId] = rdrParents($conn, $userId);

    /** @var FingerprintComposer $composer */
    $composer = app(FingerprintComposer::class);
    $stale = $composer->version() - 1;

    $conn->table('transactions')->insert(rdrRow($userId, $accountId, $runId, 1, $stale, 'a stale row'));
    $conn->table('transactions')->insert(rdrRow($userId, $accountId, $runId, 2, $composer->version(), 'a settled row'));

    /** @var FingerprintRederiveService $service */
    $service = app(FingerprintRederiveService::class);
    $outcome = $service->run(apply: true);

    $versions = $conn->table('transactions')
        ->where('user_id', $userId)
        ->orderBy('source_row_index')
        ->pluck('normalization_version')
        ->all();

    expect($outcome->isCollision())->toBeFalse()
        ->and($versions)->toBe([$composer->version(), $composer->version()]);
});
