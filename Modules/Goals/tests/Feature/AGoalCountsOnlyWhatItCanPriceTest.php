<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Goals\Public\Enums\GoalProgressState;
use Modules\Goals\Public\Services\GoalProgressQuery;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Enums\Currency;

// A goal is denominated in the currency the reader set on it, and an
// attribution in another one is not that currency's minor units. Counting it at
// face value both inflated the bar and marked a goal reached that was not.

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-15 09:00:00');

    /** @var DatabaseManager $manager */
    $manager = app(DatabaseManager::class);
    $this->conn = $manager->connection();

    /** @var User $user */
    $user = User::query()->create([
        'username' => 'gcop-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
    ]);
    $this->user = $user;

    $this->accountId = (int) $this->conn->table('accounts')->insertGetId([
        'user_id' => $user->id,
        'name' => 'GCOP account',
        'slug' => 'gcop-'.bin2hex(random_bytes(4)),
        'kind' => AccountKind::Bank->value,
        'iban' => 'NL00GCOP'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => Currency::Eur->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->runId = (int) $this->conn->table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/gcop.csv',
        'sha256' => hash('sha256', 'gcop-'.bin2hex(random_bytes(4))),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->gcopGoal = function (string $targetCurrency, int $targetMinor): int {
        return (int) $this->conn->table('goals')->insertGetId([
            'user_id' => $this->user->id,
            'name' => 'GCOP '.$targetCurrency,
            'target_minor' => $targetMinor,
            'target_currency' => $targetCurrency,
            'start_date' => '2025-01-01',
            'target_date' => '2027-01-01',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    };

    $this->gcopContribution = function (int $goalId, int $amountMinor, string $currency, int $seq = 0): void {
        $txId = $this->conn->table('transactions')->insertGetId([
            'user_id' => $this->user->id,
            'account_id' => $this->accountId,
            'import_run_id' => $this->runId,
            'fingerprint' => hash('sha256', 'gcop-'.$goalId.'-'.$seq.'-'.bin2hex(random_bytes(4))),
            'posted_at' => '2026-05-16',
            'booked_at' => '2026-05-16 00:00:00',
            'value_date' => '2026-05-16',
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'settled_amount_minor' => $amountMinor,
            'settled_currency' => $currency,
            'counterparty_normalized' => 'gcop-saver-'.$seq,
            'counterparty_name' => 'GCOP Saver',
            'normalization_version' => 1,
            'description' => 'gcop contribution',
            'type' => 'income',
            'source_format' => 'asn-csv',
            'source_row_index' => $seq,
            'fingerprint_version' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->conn->table('goal_contributions')->insert([
            'user_id' => $this->user->id,
            'goal_id' => $goalId,
            'transaction_id' => $txId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    };
});

afterEach(fn () => CarbonImmutable::setTestNow(null));

// AED is a currency the bundled snapshot does not carry, so the rate table
// cannot reach it from any target the reader can pick.
it('leaves an attribution it cannot price out of the bar rather than counting it at par', function (): void {
    $goalId = ($this->gcopGoal)(Currency::Eur->value, 100_000);
    ($this->gcopContribution)($goalId, 100_000, 'AED');

    $rows = app(GoalProgressQuery::class)->forUser($this->user);

    // "Out of the bar" is only half of it: the row also has to be able to SAY
    // what it left out, or the bar is short with nothing explaining why.
    expect($rows[0]->contributedMinor)->toBe(0)
        ->and($rows[0]->progressState)->toBe(GoalProgressState::InProgress->value)
        ->and($rows[0]->unconverted)->toBe(['AED'])
        ->and($rows[0]->isPartial())->toBeTrue();
});

it('prices an attribution in a currency it can reach', function (): void {
    $goalId = ($this->gcopGoal)(Currency::Eur->value, 100_000);
    ($this->gcopContribution)($goalId, 100_000, Currency::Usd->value);

    $rows = app(GoalProgressQuery::class)->forUser($this->user);

    // The bundled snapshot prices USD1,000.00 at EUR880.36.
    expect($rows[0]->contributedMinor)->toBe(88_036);
});

it('reads a goal denominated in a currency that is not the reader s base', function (): void {
    $goalId = ($this->gcopGoal)(Currency::Usd->value, 200_000);
    ($this->gcopContribution)($goalId, 100_000, Currency::Eur->value);

    $rows = app(GoalProgressQuery::class)->forUser($this->user);

    // EUR1,000.00 buys USD1,135.90 at the bundled rate.
    expect($rows[0]->currency)->toBe(Currency::Usd->value)
        ->and($rows[0]->contributedMinor)->toBe(113_590);
});

// The level and the projection each converted every attribution on its own, and
// convertToBase() reads the whole exchange_rates table per call.
it('prices the goal s currency once for the whole list, not once per attribution', function (): void {
    $goalId = ($this->gcopGoal)(Currency::Eur->value, 10_000_000);
    for ($i = 0; $i < 30; $i++) {
        ($this->gcopContribution)($goalId, 1_000 + $i, Currency::Usd->value, $i);
    }

    $reads = 0;
    DB::listen(function (QueryExecuted $query) use (&$reads): void {
        if (str_contains($query->sql, 'exchange_rates')) {
            $reads++;
        }
    });

    app(GoalProgressQuery::class)->forUser($this->user);

    expect($reads)->toBe(1);
});
