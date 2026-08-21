<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Counterparties\Public\Queries\CounterpartyIndexQuery;
use Modules\Counterparties\Public\Queries\CounterpartyIndexRow;

function cisqUser(): User
{
    return User::query()->create([
        'username' => 'cisq-reader',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

function cisqCounterparty(DatabaseManager $db, int $userId, string $slug, string $displayName): int
{
    return $db->connection()->table('counterparties')->insertGetId([
        'user_id' => $userId,
        'type' => 'merchant',
        'slug' => $slug,
        'display_name' => $displayName,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function cisqAccount(DatabaseManager $db, int $userId): int
{
    return $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'CISQ ASN',
        'slug' => 'cisq-asn',
        'kind' => 'bank',
        'iban' => 'NL00ASNBCISQ0001',
        'default_currency' => 'EUR',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function cisqRun(DatabaseManager $db, int $userId): int
{
    return $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/cisq-'.bin2hex(random_bytes(4)).'.csv',
        'sha256' => hash('sha256', 'cisq-run-'.bin2hex(random_bytes(4))),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function cisqTx(DatabaseManager $db, int $userId, int $accountId, int $cpId, string $postedAt, int $minor, string $description): void
{
    $db->connection()->table('transactions')->insert([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => cisqRun($db, $userId),
        'counterparty_id' => $cpId,
        'fingerprint' => hash('sha256', 'cisq-'.bin2hex(random_bytes(8))),
        'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 00:00:00',
        'value_date' => $postedAt,
        'amount_minor' => $minor,
        'currency' => 'EUR',
        'settled_amount_minor' => $minor,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'cisq-vendor',
        'counterparty_name' => 'CISQ Vendor BV',
        'normalization_version' => 1,
        'description' => $description,
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-15 09:00:00');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $this->db = $db;
    $this->user = cisqUser();
    $userId = (int) $this->user->id;
    $account = cisqAccount($db, $userId);

    $this->alpha = cisqCounterparty($db, $userId, 'alpha', 'Alpha');
    $this->bravo = cisqCounterparty($db, $userId, 'bravo', 'Bravo');
    $this->charlie = cisqCounterparty($db, $userId, 'charlie', 'Charlie');
    $this->delta = cisqCounterparty($db, $userId, 'delta', 'Delta');

    // Two on one date: the recent line has to resolve the tie on id, newest last.
    cisqTx($db, $userId, $account, $this->alpha, '2026-05-10', -1000, 'ALPHA EARLIER');
    cisqTx($db, $userId, $account, $this->alpha, '2026-05-10', -2000, 'ALPHA LATER');
    cisqTx($db, $userId, $account, $this->bravo, '2026-04-02', -3000, 'BRAVO ONE');
    cisqTx($db, $userId, $account, $this->delta, '2024-02-02', -50000, 'DELTA OLD');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * @param  iterable<CounterpartyIndexRow>  $rows
 */
function cisqBySlug(iterable $rows, string $slug): CounterpartyIndexRow
{
    foreach ($rows as $row) {
        if ($row->slug === $slug) {
            return $row;
        }
    }

    throw new RuntimeException('no row for '.$slug);
}

it('totals, averages and sparklines match the per-counterparty shape exactly', function (): void {
    $rows = app(CounterpartyIndexQuery::class)->forUser($this->user);

    $alpha = cisqBySlug($rows, 'alpha');
    expect($alpha->total12mMinor)->toBe(-3000)
        ->and($alpha->avgPerMonthMinor)->toBe(-250)
        ->and($alpha->sparkline)->toBe([0, 0, 0, 0, 0, 0, 0, 0, 0, 0, -3000, 0]);

    $bravo = cisqBySlug($rows, 'bravo');
    expect($bravo->total12mMinor)->toBe(-3000)
        ->and($bravo->sparkline)->toBe([0, 0, 0, 0, 0, 0, 0, 0, 0, -3000, 0, 0]);
});

it('gives a counterparty with no transactions at all a zeroed row, not a missing one', function (): void {
    $charlie = cisqBySlug(app(CounterpartyIndexQuery::class)->forUser($this->user), 'charlie');

    expect($charlie->total12mMinor)->toBe(0)
        ->and($charlie->avgPerMonthMinor)->toBe(0)
        ->and($charlie->recentLine)->toBeNull()
        ->and($charlie->sparkline)->toBe([0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0]);
});

it('still shows a recent line for activity older than the twelve-month total window', function (): void {
    $delta = cisqBySlug(app(CounterpartyIndexQuery::class)->forUser($this->user), 'delta');

    expect($delta->total12mMinor)->toBe(0)
        ->and($delta->sparkline)->toBe([0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0])
        ->and($delta->recentLine)->not->toBeNull()
        ->and($delta->recentLine)->toEndWith('· DELTA OLD');
});

it('breaks a same-date recent line on the newer id', function (): void {
    $alpha = cisqBySlug(app(CounterpartyIndexQuery::class)->forUser($this->user), 'alpha');

    expect($alpha->recentLine)->toEndWith('· ALPHA LATER');
});

it('orders by absolute total then by display name', function (): void {
    $rows = app(CounterpartyIndexQuery::class)->forUser($this->user);

    expect($rows->pluck('slug')->all())->toBe(['alpha', 'bravo', 'charlie', 'delta']);
});

it('costs a fixed number of queries regardless of how many counterparties there are', function (): void {
    $userId = (int) $this->user->id;
    $account = cisqAccount2($this->db, $userId);
    for ($i = 0; $i < 12; $i++) {
        $cpId = cisqCounterparty($this->db, $userId, 'extra-'.$i, 'Extra '.$i);
        cisqTx($this->db, $userId, $account, $cpId, '2026-03-0'.($i % 9 + 1), -100 * ($i + 1), 'EXTRA '.$i);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    app(CounterpartyIndexQuery::class)->forUser($this->user);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBeLessThanOrEqual(6);
});

function cisqAccount2(DatabaseManager $db, int $userId): int
{
    return $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'CISQ ASN 2',
        'slug' => 'cisq-asn-2',
        'kind' => 'bank',
        'iban' => 'NL00ASNBCISQ0002',
        'default_currency' => 'EUR',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}
