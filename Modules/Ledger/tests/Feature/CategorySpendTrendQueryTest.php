<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Services\CategorySpendTrendQuery;
use Modules\Ledger\Public\Services\PeriodQuery;

function trendCategory(DatabaseManager $db, int $userId, string $name): int
{
    return $db->connection()->table('categories')->insertGetId([
        'user_id' => $userId, 'name' => $name, 'slug' => strtolower($name).'-'.bin2hex(random_bytes(3)),
        'kind' => 'expense', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
}

function trendTx(DatabaseManager $db, int $userId, int $categoryId, int $minor, string $postedAt): void
{
    static $i = 0;
    $i++;
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId, 'name' => 'ASN', 'slug' => 'tr-'.bin2hex(random_bytes(4)),
        'kind' => 'bank', 'iban' => 'NL00TRND'.str_pad((string) $i, 8, '0', STR_PAD_LEFT), 'default_currency' => 'EUR',
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId, 'source_format' => 'asn-csv', 'raw_file_path' => '/tmp/tr.csv',
        'sha256' => str_pad('tr'.$i, 64, 'a', STR_PAD_LEFT), 'uploaded_at' => '2026-01-01 00:00:00', 'status' => 'previewed',
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
    $db->connection()->table('transactions')->insert([
        'user_id' => $userId, 'account_id' => $accountId, 'import_run_id' => $runId, 'category_id' => $categoryId,
        'fingerprint' => str_pad('tr'.$i, 64, 'c', STR_PAD_LEFT), 'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 00:00:00', 'value_date' => $postedAt,
        'amount_minor' => $minor, 'currency' => 'EUR', 'settled_amount_minor' => $minor, 'settled_currency' => 'EUR',
        'counterparty_normalized' => 'trend', 'counterparty_name' => 'TREND', 'normalization_version' => 1,
        'type' => 'expense', 'source_format' => 'asn-csv', 'source_row_index' => $i,
        'fingerprint_version' => 3, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
}

beforeEach(function (): void {
    $this->db = app(DatabaseManager::class);
    $this->user = User::create(['username' => 'trend-fixture', 'password' => 'fixture-password-12chars', 'period_start_day' => 1]);
    $this->actingAs($this->user);
});

it('compares current vs previous period spend and ranks the movers', function (): void {
    $periods = app(PeriodQuery::class);
    $current = $periods->current();
    $previous = $periods->previous($current);

    $groceries = trendCategory($this->db, $this->user->id, 'Groceries');
    $eating = trendCategory($this->db, $this->user->id, 'Eating out');

    trendTx($this->db, $this->user->id, $groceries, -40000, $current->start->addDays(2)->toDateString());
    trendTx($this->db, $this->user->id, $groceries, -30000, $previous->start->addDays(2)->toDateString());
    trendTx($this->db, $this->user->id, $eating, -3000, $current->start->addDays(3)->toDateString());
    trendTx($this->db, $this->user->id, $eating, -5000, $previous->start->addDays(3)->toDateString());

    $trend = app(CategorySpendTrendQuery::class)->forUser($this->user);

    expect($trend->currentTotalMinor)->toBe(43000);
    expect($trend->previousTotalMinor)->toBe(35000);
    expect($trend->totalDeltaMinor)->toBe(8000);
    // Biggest absolute mover first.
    expect($trend->movers[0]->name)->toBe('Groceries');
    expect($trend->movers[0]->deltaMinor)->toBe(10000);
    expect($trend->movers[0]->direction())->toBe('up');
    expect($trend->movers[1]->name)->toBe('Eating out');
    expect($trend->movers[1]->deltaMinor)->toBe(-2000);
    expect($trend->movers[1]->direction())->toBe('down');
});

it('reports no comparison when there is no spend', function (): void {
    $trend = app(CategorySpendTrendQuery::class)->forUser($this->user);

    expect($trend->hasComparison())->toBeFalse();
    expect($trend->movers)->toBe([]);
});

it('counts a split transaction\'s legs individually, never the parent', function (): void {
    $periods = app(PeriodQuery::class);
    $current = $periods->current();

    $groceries = trendCategory($this->db, $this->user->id, 'Groceries');
    $household = trendCategory($this->db, $this->user->id, 'Household');

    // The parent keeps a vestigial category_id, so the roll-up has to key off
    // leg presence instead.
    $postedAt = $current->start->addDays(2)->toDateString();
    $txId = $this->db->connection()->table('transactions')->insertGetId([
        'user_id' => $this->user->id,
        'account_id' => $this->db->connection()->table('accounts')->insertGetId([
            'user_id' => $this->user->id, 'name' => 'ASN', 'slug' => 'tr-split-'.bin2hex(random_bytes(4)),
            'kind' => 'bank', 'iban' => 'NL00SPLT'.bin2hex(random_bytes(4)), 'default_currency' => 'EUR',
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]),
        'import_run_id' => $this->db->connection()->table('import_runs')->insertGetId([
            'user_id' => $this->user->id, 'source_format' => 'asn-csv', 'raw_file_path' => '/tmp/tr-split.csv',
            'sha256' => str_pad('trsplit', 64, 'a', STR_PAD_LEFT), 'uploaded_at' => '2026-01-01 00:00:00', 'status' => 'previewed',
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]),
        'category_id' => $groceries,
        'fingerprint' => str_pad('trsplit', 64, 'c', STR_PAD_LEFT), 'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 00:00:00', 'value_date' => $postedAt,
        'amount_minor' => -8000, 'currency' => 'EUR', 'settled_amount_minor' => -8000, 'settled_currency' => 'EUR',
        'counterparty_normalized' => 'trend split', 'counterparty_name' => 'TREND SPLIT', 'normalization_version' => 1,
        'type' => 'expense', 'source_format' => 'asn-csv', 'source_row_index' => 1,
        'fingerprint_version' => 3, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
    $this->db->connection()->table('transaction_splits')->insert([
        ['user_id' => $this->user->id, 'transaction_id' => $txId, 'category_id' => $groceries, 'settled_amount_minor' => -6000, 'settled_currency' => 'EUR', 'note' => null, 'sort_order' => 0, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00'],
        ['user_id' => $this->user->id, 'transaction_id' => $txId, 'category_id' => $household, 'settled_amount_minor' => -2000, 'settled_currency' => 'EUR', 'note' => null, 'sort_order' => 1, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00'],
    ]);

    $trend = app(CategorySpendTrendQuery::class)->forUser($this->user);

    expect($trend->currentTotalMinor)->toBe(8000); // not 16000
    $byName = [];
    foreach ($trend->movers as $mover) {
        $byName[$mover->name] = $mover;
    }
    expect($byName['Groceries']->currentMinor)->toBe(6000);
    expect($byName['Household']->currentMinor)->toBe(2000);
});

// The card is entirely differences: a total against last period's, and one
// signed delta per category. Drawn for a reader whose ledger opened four days
// ago, every one of them was measured against a month the ledger never covered
// — EUR 250,00 spent showed as +EUR 250,00, a full-amount rise, in the rose
// colour that means "worth noticing".
it('offers no comparison when the ledger opens inside the current period', function (): void {
    $periods = app(PeriodQuery::class);
    $current = $periods->current();

    $groceries = trendCategory($this->db, $this->user->id, 'Groceries');
    trendTx($this->db, $this->user->id, $groceries, -25000, $current->start->addDays(3)->toDateString());

    $trend = app(CategorySpendTrendQuery::class)->forUser($this->user);

    expect($trend->currentTotalMinor)->toBe(25000)
        ->and($trend->previousPeriodIsReachable)->toBeFalse()
        ->and($trend->hasComparison())->toBeFalse();
});

// A month a reader genuinely spent nothing in is a real comparison and one of
// the more useful ones. The rule is about the ledger's reach, not about the
// previous period's total, and reading it off the total would silence exactly
// this reader.
it('still compares against a previous period the reader genuinely spent nothing in', function (): void {
    $periods = app(PeriodQuery::class);
    $current = $periods->current();
    $previous = $periods->previous($current);

    $groceries = trendCategory($this->db, $this->user->id, 'Groceries');
    // A charge and its refund inside the previous period: rows the ledger
    // demonstrably holds, netting to nothing spent.
    trendTx($this->db, $this->user->id, $groceries, -12000, $previous->start->addDays(1)->toDateString());
    trendTx($this->db, $this->user->id, $groceries, 12000, $previous->start->addDays(4)->toDateString());
    trendTx($this->db, $this->user->id, $groceries, -25000, $current->start->addDays(3)->toDateString());

    $trend = app(CategorySpendTrendQuery::class)->forUser($this->user);

    expect($trend->previousTotalMinor)->toBe(0)
        ->and($trend->previousPeriodIsReachable)->toBeTrue()
        ->and($trend->hasComparison())->toBeTrue();
});

// A gap between two months of records is a fact about the reader, not about
// the ledger's reach: the previous period holds no row at all and the
// comparison is still real, because the ledger covers it.
it('still compares across a gap month with no rows in it', function (): void {
    $periods = app(PeriodQuery::class);
    $current = $periods->current();
    $previous = $periods->previous($current);
    $beforeThat = $periods->previous($previous);

    $groceries = trendCategory($this->db, $this->user->id, 'Groceries');
    trendTx($this->db, $this->user->id, $groceries, -40000, $beforeThat->start->addDays(2)->toDateString());
    trendTx($this->db, $this->user->id, $groceries, -25000, $current->start->addDays(3)->toDateString());

    $rowsInPrevious = $this->db->connection()->table('transactions')
        ->where('user_id', $this->user->id)
        ->where('posted_at', '>=', $previous->start->toDateString())
        ->where('posted_at', '<', $previous->endExclusive->toDateString())
        ->count();

    expect($rowsInPrevious)->toBe(0, 'the gap month must actually be empty');

    $trend = app(CategorySpendTrendQuery::class)->forUser($this->user);

    expect($trend->previousPeriodIsReachable)->toBeTrue()
        ->and($trend->hasComparison())->toBeTrue()
        ->and($trend->totalDeltaMinor)->toBe(25000);
});

// The boundary itself: one row on the previous period's last day is inside the
// ledger's reach, and the same row a day later is not. posted_at is a DATE
// column, so a bound carrying a time would drop that day in SQLite.
it('counts the previous period\'s own last day as reach, and the day after as none', function (): void {
    $periods = app(PeriodQuery::class);
    $previous = $periods->previous($periods->current());
    $lastDayOfPrevious = $previous->endExclusive->subDay()->toDateString();

    $groceries = trendCategory($this->db, $this->user->id, 'Groceries');
    trendTx($this->db, $this->user->id, $groceries, -25000, $lastDayOfPrevious);

    expect(app(CategorySpendTrendQuery::class)->forUser($this->user)->previousPeriodIsReachable)
        ->toBeTrue('a row on '.$lastDayOfPrevious.' is inside the reach');

    $other = User::create(['username' => 'trend-boundary', 'password' => 'fixture-password-12chars', 'period_start_day' => 1]);
    trendTx($this->db, $other->id, trendCategory($this->db, $other->id, 'Groceries'), -25000, $previous->endExclusive->toDateString());
    $this->actingAs($other);

    expect(app(CategorySpendTrendQuery::class)->forUser($other)->previousPeriodIsReachable)
        ->toBeFalse('a row on '.$previous->endExclusive->toDateString().' is the first day of the current period');
});
