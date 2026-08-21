<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Recurring\Internal\Queries\SeriesAccountResolver;
use Modules\Recurring\Models\RecurringSeries;
use Modules\Recurring\Models\RecurringSeriesOccurrence;

function sarUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
}

function sarAccount(User $user, string $slug, string $name): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => $name,
        'slug' => $slug,
        'kind' => 'bank',
        'iban' => 'NL00SAR'.str_pad(substr($slug, 0, 10), 11, '0', STR_PAD_RIGHT),
        'default_currency' => 'EUR',
    ]);
}

function sarRun(User $user, string $sha): ImportRun
{
    return ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/sar.csv',
        'sha256' => str_pad($sha, 64, '0'),
        'uploaded_at' => CarbonImmutable::parse('2026-05-01 00:00:00'),
        'status' => 'previewed',
    ]);
}

function sarSeries(User $user, string $name): RecurringSeries
{
    return RecurringSeries::query()->create([
        'user_id' => $user->id,
        'direction' => 'expense',
        'detected_name' => $name,
        'state' => 'approved',
        'cadence' => 'monthly',
        'latest_amount_minor' => -1099,
        'latest_currency' => 'EUR',
        'monthly_equivalent_minor' => -1099,
        'variance_tolerance_percent' => 25,
        'cluster_key' => 'expense::'.$name.'::eur::monthly',
        'cluster_counterparty_key' => $name,
        'next_expected_at' => '2026-06-01',
        'next_expected_confidence_low' => false,
    ]);
}

function sarTransaction(User $user, Account $account, ImportRun $run, string $ref): Transaction
{
    return Transaction::query()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => '2026-05-01',
        'booked_at' => '2026-05-01 12:00:00',
        'value_date' => '2026-05-01',
        'amount_minor' => -1099,
        'currency' => 'EUR',
        'settled_amount_minor' => -1099,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Merchant '.$ref,
        'counterparty_normalized' => 'merchant-'.$ref,
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => crc32($ref) % 100000,
        'fingerprint' => substr(hash('sha256', $ref), 0, 64),
        'fingerprint_version' => 3,
    ]);
}

function sarOccurrence(User $user, RecurringSeries $series, Transaction $tx, string $observedAt): RecurringSeriesOccurrence
{
    return RecurringSeriesOccurrence::query()->create([
        'user_id' => $user->id,
        'recurring_series_id' => $series->id,
        'transaction_id' => $tx->id,
        'observed_at' => $observedAt,
        'observed_amount_minor' => -1099,
        'observed_currency' => 'EUR',
    ]);
}

/**
 * The shape this test replaces: fetch every joined occurrence row ordered
 * `observed_at DESC, rso.id DESC`, then keep the first row seen per series.
 *
 * @param  list<int>  $seriesIds
 * @return array<int, int>
 */
function sarLegacyLatestOccurrence(DatabaseManager $db, array $seriesIds, User $user): array
{
    $rows = $db->connection()->table('recurring_series_occurrences as rso')
        ->join('transactions as t', 't.id', '=', 'rso.transaction_id')
        ->where('rso.user_id', $user->id)
        ->where('t.user_id', $user->id)
        ->whereIn('rso.recurring_series_id', $seriesIds)
        ->orderByDesc('rso.observed_at')
        ->orderByDesc('rso.id')
        ->get(['rso.recurring_series_id as series_id', 't.account_id as account_id']);

    $map = [];
    foreach ($rows as $row) {
        $seriesId = (int) $row->series_id;
        if ($seriesId > 0 && ! isset($map[$seriesId])) {
            $map[$seriesId] = (int) $row->account_id;
        }
    }

    return $map;
}

/**
 * @return array{
 *     db: DatabaseManager,
 *     user: User,
 *     other: User,
 *     seriesIds: list<int>,
 *     expected: array<string, int>,
 *     accounts: array<string, int>,
 *     otherSeriesId: int,
 * }
 */
function sarFixture(DatabaseManager $db): array
{
    $user = sarUser('sar-owner');
    $other = sarUser('sar-other');

    $current = sarAccount($user, 'sar-current', 'Current');
    $savings = sarAccount($user, 'sar-savings', 'Savings');
    $joint = sarAccount($user, 'sar-joint', 'Joint');
    $foreign = sarAccount($other, 'sar-foreign', 'Foreign');

    $run = sarRun($user, 'a');
    $otherRun = sarRun($other, 'b');

    $long = sarSeries($user, 'long-history');
    $tied = sarSeries($user, 'tied-observed-at');
    $crossUser = sarSeries($user, 'cross-user-legs');
    $noOccurrences = sarSeries($user, 'no-occurrences');
    $outsideWindow = sarSeries($user, 'never-asked-for');
    $otherUsers = sarSeries($other, 'belongs-to-other');

    // 40 months of history, oldest first, so the newest occurrence is the
    // last one written — the row a naive `first()` on insertion order misses.
    for ($i = 1; $i <= 40; $i++) {
        $account = $i === 40 ? $savings : $current;
        $tx = sarTransaction($user, $account, $run, 'long-'.$i);
        sarOccurrence($user, $long, $tx, CarbonImmutable::parse('2023-01-01')->addMonths($i)->toDateString());
    }

    // Two occurrences share an observed_at. The ordering's secondary key is
    // rso.id DESC, so the later-written row (Joint) is the answer.
    $olderTx = sarTransaction($user, $current, $run, 'tied-old');
    sarOccurrence($user, $tied, $olderTx, '2026-03-15');
    $tieLoserTx = sarTransaction($user, $savings, $run, 'tie-loser');
    sarOccurrence($user, $tied, $tieLoserTx, '2026-04-15');
    $tieWinnerTx = sarTransaction($user, $joint, $run, 'tie-winner');
    sarOccurrence($user, $tied, $tieWinnerTx, '2026-04-15');

    // The newest leg points at a transaction another user owns, so the join's
    // ownership filter must drop it and fall back to the older owned leg.
    $ownedTx = sarTransaction($user, $current, $run, 'cross-owned');
    sarOccurrence($user, $crossUser, $ownedTx, '2026-02-01');
    $foreignTx = sarTransaction($other, $foreign, $otherRun, 'cross-foreign');
    sarOccurrence($user, $crossUser, $foreignTx, '2026-05-01');

    // Occurrences for a series nobody asked about, and occurrences owned by
    // the other user — neither may reach the map.
    $strayTx = sarTransaction($user, $joint, $run, 'stray');
    sarOccurrence($user, $outsideWindow, $strayTx, '2026-05-20');
    $otherTx = sarTransaction($other, $foreign, $otherRun, 'other-user');
    sarOccurrence($other, $otherUsers, $otherTx, '2026-05-21');

    return [
        'db' => $db,
        'user' => $user,
        'other' => $other,
        'seriesIds' => [
            (int) $long->id,
            (int) $tied->id,
            (int) $crossUser->id,
            (int) $noOccurrences->id,
            (int) $otherUsers->id,
        ],
        'expected' => [
            'long' => (int) $long->id,
            'tied' => (int) $tied->id,
            'crossUser' => (int) $crossUser->id,
            'noOccurrences' => (int) $noOccurrences->id,
        ],
        'accounts' => [
            'current' => (int) $current->id,
            'savings' => (int) $savings->id,
            'joint' => (int) $joint->id,
        ],
        'otherSeriesId' => (int) $otherUsers->id,
    ];
}

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
});

it('resolves the same account for every series as the fetch-everything-and-filter-in-PHP shape did', function (): void {
    $fixture = sarFixture($this->db);

    /** @var SeriesAccountResolver $resolver */
    $resolver = $this->app->make(SeriesAccountResolver::class);
    $resolved = $resolver->forSeriesIds($fixture['seriesIds'], $fixture['user']);

    $legacy = sarLegacyLatestOccurrence($this->db, $fixture['seriesIds'], $fixture['user']);

    // The occurrence-derived half must be byte-identical; the fallback half
    // (a series with no occurrence at all) is layered on top of it.
    $occurrenceDerived = array_intersect_key($resolved, $legacy);
    ksort($occurrenceDerived);
    ksort($legacy);
    expect($occurrenceDerived)->toBe($legacy);

    expect($resolved[$fixture['expected']['long']])->toBe($fixture['accounts']['savings']);
    expect($resolved[$fixture['expected']['tied']])->toBe($fixture['accounts']['joint']);
    expect($resolved[$fixture['expected']['crossUser']])->toBe($fixture['accounts']['current']);

    // No occurrence of its own, so it takes the alphabetically-first account.
    expect($resolved[$fixture['expected']['noOccurrences']])->toBe($fixture['accounts']['current']);

    // Another user's series id was passed in and gets nothing back.
    expect($resolved)->not->toHaveKey($fixture['otherSeriesId']);
});

it('fetches one occurrence row per series instead of the whole history', function (): void {
    $fixture = sarFixture($this->db);

    /** @var list<array{sql: string, bindings: list<mixed>}> $log */
    $log = [];
    $this->db->connection()->listen(static function ($query) use (&$log): void {
        $log[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
    });

    /** @var SeriesAccountResolver $resolver */
    $resolver = $this->app->make(SeriesAccountResolver::class);
    $resolver->forSeriesIds($fixture['seriesIds'], $fixture['user']);

    $occurrenceQueries = array_values(array_filter(
        $log,
        static fn (array $entry): bool => str_contains($entry['sql'], 'recurring_series_occurrences'),
    ));
    expect($occurrenceQueries)->toHaveCount(1);

    // Re-run the driving query and count what it actually hands back. The old
    // shape returned 45 joined rows for this fixture to produce 3 answers.
    $fetched = $this->db->connection()->select(
        $occurrenceQueries[0]['sql'],
        $occurrenceQueries[0]['bindings'],
    );

    expect(count($fetched))->toBeLessThanOrEqual(count($fixture['seriesIds']));

    $legacyRowCount = $this->db->connection()->table('recurring_series_occurrences as rso')
        ->join('transactions as t', 't.id', '=', 'rso.transaction_id')
        ->where('rso.user_id', $fixture['user']->id)
        ->where('t.user_id', $fixture['user']->id)
        ->whereIn('rso.recurring_series_id', $fixture['seriesIds'])
        ->count();

    expect($legacyRowCount)->toBeGreaterThan(40);
});

it('picks the deterministic tie-break row when two occurrences share an observed_at', function (): void {
    $fixture = sarFixture($this->db);

    /** @var SeriesAccountResolver $resolver */
    $resolver = $this->app->make(SeriesAccountResolver::class);

    $first = $resolver->forSeriesIds($fixture['seriesIds'], $fixture['user']);
    $second = $resolver->forSeriesIds($fixture['seriesIds'], $fixture['user']);

    expect($first)->toBe($second);
    expect($first[$fixture['expected']['tied']])->toBe($fixture['accounts']['joint']);
});
