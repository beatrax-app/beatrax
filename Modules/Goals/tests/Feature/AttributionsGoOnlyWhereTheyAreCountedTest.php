<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Core\Models\User;
use Modules\Goals\Models\Goal;
use Modules\Goals\Public\Dto\GoalAttributionRow;
use Modules\Goals\Public\Services\GoalContributionQuery;
use Modules\Goals\Public\Services\GoalContributionWriter;
use Modules\Goals\Public\Services\GoalProgressQuery;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Pots\Models\Pot;
use Modules\Pots\Public\Services\PotWriter;

// A goal funded by a pot takes its whole figure from that pot, so an
// attribution to one is discarded on the next render. The picker offered those
// goals anyway and the transaction screen then listed the discarded claim as
// fact -- money that moved the bar nowhere and said it had.

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'wessel',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN',
        'slug' => 'asn',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);

    $this->run = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/attr.xml',
        'sha256' => str_repeat('f', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    $this->credit = function (int $amountMinor, int $seq): Transaction {
        return Transaction::create([
            'user_id' => $this->user->id,
            'account_id' => $this->account->id,
            'type' => 'transfer_in',
            'posted_at' => CarbonImmutable::now()->subDays(10)->toDateString(),
            'booked_at' => CarbonImmutable::now()->subDays(10)->toDateString().' 12:00:00',
            'value_date' => CarbonImmutable::now()->subDays(10)->toDateString(),
            'amount_minor' => $amountMinor,
            'currency' => 'EUR',
            'settled_amount_minor' => $amountMinor,
            'settled_currency' => 'EUR',
            'counterparty_name' => 'Sparen',
            'counterparty_normalized' => 'sparen-'.$seq,
            'normalization_version' => 1,
            'source_format' => 'camt053',
            'import_run_id' => $this->run->id,
            'source_row_index' => $seq,
            'fingerprint' => str_pad('attr'.$seq, 64, '0', STR_PAD_LEFT),
            'fingerprint_version' => 1,
        ]);
    };

    $this->potFunded = Goal::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Japan trip',
        'target_minor' => 500000,
        'start_date' => CarbonImmutable::now()->subDays(60)->toDateString(),
        'target_date' => CarbonImmutable::now()->addYearNoOverflow()->toDateString(),
        'status' => 'active',
    ]);

    $this->pot = Pot::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'goal_id' => $this->potFunded->id,
        'category_id' => null,
    ]);

    $this->unfunded = Goal::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Winterbanden',
        'target_minor' => 40000,
        'start_date' => CarbonImmutable::now()->subDays(60)->toDateString(),
        'target_date' => CarbonImmutable::now()->addYearNoOverflow()->toDateString(),
        'status' => 'active',
    ]);
});

it('keeps a pot-funded goal out of the attribution picker', function (): void {
    $names = array_map(
        static fn (GoalAttributionRow $row): string => $row->goalName,
        app(GoalContributionQuery::class)->attributableGoals($this->user),
    );

    expect($names)->toBe(['Winterbanden']);
});

it('refuses an attribution to a pot-funded goal instead of discarding it later', function (): void {
    $transaction = ($this->credit)(385000, 1);

    $accepted = app(GoalContributionWriter::class)
        ->attribute($this->user, $this->potFunded->id, $transaction->id);

    expect($accepted)->toBeFalse();

    $this->assertDatabaseMissing('goal_contributions', [
        'goal_id' => $this->potFunded->id,
        'transaction_id' => $transaction->id,
    ]);
});

it('still accepts an attribution to a goal no pot funds', function (): void {
    $transaction = ($this->credit)(10000, 2);

    expect(app(GoalContributionWriter::class)->attribute($this->user, $this->unfunded->id, $transaction->id))
        ->toBeTrue();

    $rows = app(GoalProgressQuery::class)->forUser($this->user);
    $row = array_values(array_filter($rows, fn ($r) => $r->id === $this->unfunded->id))[0];

    expect($row->contributedMinor)->toBe(10000);
});

it('offers the goal again once its pot is archived', function (): void {
    app(PotWriter::class)->archive($this->user, $this->pot->id);

    $names = array_map(
        static fn (GoalAttributionRow $row): string => $row->goalName,
        app(GoalContributionQuery::class)->attributableGoals($this->user),
    );

    expect($names)->toContain('Japan trip');
});
