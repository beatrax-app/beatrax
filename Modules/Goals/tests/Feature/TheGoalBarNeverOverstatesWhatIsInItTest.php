<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Goals\Internal\Http\Livewire\GoalsPage;
use Modules\Goals\Public\Enums\GoalProgressState;
use Modules\Goals\Public\Services\GoalProgressQuery;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Enums\Currency;

// Two claims one goal card makes about money it has not got: a bar that fills
// to 100% while five euro are still missing, and a bar that quietly leaves out
// an attribution it could not price without saying so.

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-15 09:00:00');

    /** @var DatabaseManager $manager */
    $manager = app(DatabaseManager::class);
    $this->conn = $manager->connection();

    /** @var User $user */
    $user = User::query()->create([
        'username' => 'bar-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
    ]);
    $this->user = $user;
    $this->actingAs($user);

    $this->accountId = (int) $this->conn->table('accounts')->insertGetId([
        'user_id' => $user->id,
        'name' => 'Bar account',
        'slug' => 'bar-'.bin2hex(random_bytes(4)),
        'kind' => AccountKind::Bank->value,
        'iban' => 'NL00BARR'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => Currency::Eur->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->runId = (int) $this->conn->table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/bar.csv',
        'sha256' => hash('sha256', 'bar-'.bin2hex(random_bytes(4))),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->barGoal = function (int $targetMinor): int {
        return (int) $this->conn->table('goals')->insertGetId([
            'user_id' => $this->user->id,
            'name' => 'Nieuwe laptop',
            'target_minor' => $targetMinor,
            'target_currency' => Currency::Eur->value,
            'start_date' => '2026-01-01',
            'target_date' => '2027-01-01',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    };

    $this->barContribution = function (int $goalId, int $amountMinor, string $currency, int $seq = 0): void {
        $txId = $this->conn->table('transactions')->insertGetId([
            'user_id' => $this->user->id,
            'account_id' => $this->accountId,
            'import_run_id' => $this->runId,
            'fingerprint' => hash('sha256', 'bar-'.$goalId.'-'.$seq.'-'.bin2hex(random_bytes(4))),
            'posted_at' => '2026-05-16',
            'booked_at' => '2026-05-16 00:00:00',
            'value_date' => '2026-05-16',
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'settled_amount_minor' => $amountMinor,
            'settled_currency' => $currency,
            'counterparty_normalized' => 'bar-saver-'.$seq,
            'counterparty_name' => 'Bar Saver',
            'normalization_version' => 1,
            'description' => 'bar contribution',
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

it('draws 99 and says 99 for a goal five euro short of its target', function (): void {
    $goalId = ($this->barGoal)(500_000);
    ($this->barContribution)($goalId, 499_500, Currency::Eur->value);

    $row = app(GoalProgressQuery::class)->forUser($this->user)[0];

    expect($row->contributedMinor)->toBe(499_500)
        ->and($row->progressState)->toBe(GoalProgressState::InProgress->value)
        ->and($row->percentComplete())->toBe(99);

    $html = (string) Livewire::test(GoalsPage::class)->html();

    expect($html)
        ->toContain('aria-valuenow="99"')
        ->not->toContain('aria-valuenow="100"')
        ->not->toContain('100% complete');
});

// AED is a currency the bundled snapshot does not carry, so the rate table
// cannot reach it from any target the reader can pick.
it('names the currency it could not price rather than leaving the bar short in silence', function (): void {
    $goalId = ($this->barGoal)(200_000);
    ($this->barContribution)($goalId, 100_000, Currency::Eur->value, 1);
    ($this->barContribution)($goalId, 100_000, 'AED', 2);

    $row = app(GoalProgressQuery::class)->forUser($this->user)[0];

    expect($row->contributedMinor)->toBe(100_000)
        ->and($row->unconverted)->toBe(['AED'])
        ->and($row->isPartial())->toBeTrue()
        ->and($row->unconvertedList())->toBe('AED');

    $html = (string) Livewire::test(GoalsPage::class)->html();

    expect(substr_count($html, 'data-not-converted="true"'))->toBeGreaterThan(0)
        ->and($html)->toContain('AED');
});

it('says nothing about conversion when every attribution could be priced', function (): void {
    $goalId = ($this->barGoal)(200_000);
    ($this->barContribution)($goalId, 100_000, Currency::Eur->value, 3);

    expect(app(GoalProgressQuery::class)->forUser($this->user)[0]->isPartial())->toBeFalse();

    expect((string) Livewire::test(GoalsPage::class)->html())
        ->not->toContain('data-not-converted="true"');
});
