<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Chains\Public\Contracts\UpsertsCardStatements;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;

const CSU_SUMMARIES = 250;

function csuUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
}

function csuAccount(User $user, string $slug, string $kind, string $iban): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'csu '.$slug,
        'slug' => $slug,
        'kind' => $kind,
        'iban' => $iban,
        'default_currency' => 'EUR',
    ]);
}

/**
 * @param  list<array{account: Account, period: int, start: ?string, end: ?string, closing: int, currency: ?string}>  $specs
 * @return list<int> the created import-run ids, in spec order
 */
function csuSeedSummaries(DatabaseManager $db, User $user, array $specs, string $seedPrefix): array
{
    $runs = [];
    $summaries = [];
    foreach ($specs as $i => $spec) {
        $runs[] = [
            'user_id' => $user->id,
            'source_format' => 'ics-pdf',
            'raw_file_path' => '/tmp/csu.pdf',
            'sha256' => substr(hash('sha256', $seedPrefix.$i), 0, 64),
            'uploaded_at' => '2026-05-01 00:00:00',
            'status' => 'previewed',
            'created_at' => '2026-05-01 00:00:00',
            'updated_at' => '2026-05-01 00:00:00',
        ];
    }
    $db->connection()->table('import_runs')->insert($runs);

    $runIds = $db->connection()->table('import_runs')
        ->where('user_id', $user->id)
        ->orderBy('id')
        ->pluck('id')
        ->map(static fn (mixed $id): int => (int) $id)
        ->all();

    foreach ($specs as $i => $spec) {
        $summaries[] = [
            'user_id' => $user->id,
            'import_run_id' => $runIds[$i],
            'account_id' => $spec['account']->id,
            'iban_owner' => 'NL00CSU0000000000',
            'period_start' => $spec['start'],
            'period_end' => $spec['end'],
            'closing_balance_minor' => $spec['closing'],
            'closing_balance_currency' => $spec['currency'],
            'entry_count' => 3,
            'created_at' => '2026-05-01 00:00:00',
            'updated_at' => '2026-05-01 00:00:00',
        ];
    }
    $db->connection()->table('statement_summaries')->insert($summaries);

    return $runIds;
}

/**
 * CSU_SUMMARIES ICS summaries — more than one chunk of them — with a pair
 * sharing a period (the unique index decides), two carrying no period at all,
 * one with no currency, plus a non-ICS account's summaries and a second
 * user's, neither of which may be promoted.
 *
 * @return array{user: User, other: User}
 */
function csuFixture(DatabaseManager $db): array
{
    $user = csuUser('csu-owner');
    $other = csuUser('csu-other');

    $card = csuAccount($user, 'csu-ics', 'ics_card', 'CSU-ICS');
    $bank = csuAccount($user, 'csu-asn', 'bank', 'NL57ASNB0123456789');
    $foreign = csuAccount($other, 'csu-foreign', 'ics_card', 'CSU-FOREIGN');

    $specs = [];
    for ($i = 1; $i <= CSU_SUMMARIES; $i++) {
        $start = CarbonImmutable::parse('2020-01-01')->addMonths($i);
        $specs[] = [
            'account' => $card,
            'period' => $i,
            'start' => $start->toDateTimeString(),
            'end' => $start->endOfMonth()->toDateTimeString(),
            'closing' => -(10000 + $i),
            'currency' => $i % 50 === 0 ? null : 'EUR',
        ];
    }

    // Same account and period as the first summary, read from a second import
    // of the same statement.
    $specs[] = [
        'account' => $card,
        'period' => 1,
        'start' => $specs[0]['start'],
        'end' => $specs[0]['end'],
        'closing' => -99999,
        'currency' => 'EUR',
    ];

    // No period: nothing to key a statement on, so nothing is promoted.
    $specs[] = ['account' => $card, 'period' => 0, 'start' => null, 'end' => '2026-01-31 23:59:59', 'closing' => -500, 'currency' => 'EUR'];
    $specs[] = ['account' => $card, 'period' => 0, 'start' => '2026-01-01 00:00:00', 'end' => null, 'closing' => -500, 'currency' => 'EUR'];

    // A bank account is not a card: its summaries never become statements.
    $specs[] = ['account' => $bank, 'period' => 0, 'start' => '2026-02-01 00:00:00', 'end' => '2026-02-28 23:59:59', 'closing' => -700, 'currency' => 'EUR'];

    csuSeedSummaries($db, $user, $specs, 'owner');
    csuSeedSummaries($db, $other, [
        ['account' => $foreign, 'period' => 1, 'start' => '2026-03-01 00:00:00', 'end' => '2026-03-31 23:59:59', 'closing' => -1234, 'currency' => 'EUR'],
    ], 'other');

    return ['user' => $user, 'other' => $other];
}

function csuDump(DatabaseManager $db): string
{
    $rows = $db->connection()->table('card_statements')
        ->orderBy('user_id')->orderBy('account_id')->orderBy('period_start')->orderBy('id')
        ->get(['user_id', 'account_id', 'period_start', 'period_end', 'total_amount_minor', 'open_balance_minor', 'currency', 'state'])
        ->all();

    return (string) json_encode($rows, JSON_UNESCAPED_SLASHES);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-05-30 09:00:00');
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('promotes the same statements the row-at-a-time loop promoted, and no more on a re-run', function (): void {
    $fixture = csuFixture($this->db);

    /** @var UpsertsCardStatements $upserter */
    $upserter = $this->app->make(UpsertsCardStatements::class);

    $inserted = $upserter->upsertForUser($fixture['user']);
    $afterFirst = csuDump($this->db);

    // The duplicate period and the two period-less rows are the difference
    // between the summary count and the statement count.
    expect($inserted)->toBe(CSU_SUMMARIES);
    expect($this->db->connection()->table('card_statements')->count())->toBe(CSU_SUMMARIES);

    expect($upserter->upsertForUser($fixture['user']))->toBe(0);
    expect(csuDump($this->db))->toBe($afterFirst);

    $golden = (string) file_get_contents(__DIR__.'/../fixtures/card-statement-promotion.json');
    expect($afterFirst)->toBe(trim($golden));
});

it('promotes a chunk per statement rather than a statement per row', function (): void {
    $fixture = csuFixture($this->db);

    /** @var list<string> $log */
    $log = [];
    $this->db->connection()->listen(static function ($query) use (&$log): void {
        $log[] = $query->sql;
    });

    /** @var UpsertsCardStatements $upserter */
    $upserter = $this->app->make(UpsertsCardStatements::class);
    $upserter->upsertForUser($fixture['user']);

    $inserts = count(array_filter($log, static fn (string $sql): bool => str_contains($sql, 'into "card_statements"')));
    $reads = count(array_filter($log, static fn (string $sql): bool => str_contains($sql, 'from "statement_summaries"')));

    // 254 promotable summaries at 200 per chunk: two inserts, and the reads
    // are the chunk walk plus its terminating empty page. The row-at-a-time
    // shape issued one insert per summary off a single unbounded read.
    expect($inserts)->toBe(2);
    expect($reads)->toBeLessThanOrEqual(3);
});
