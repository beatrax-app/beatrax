<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Goals\Models\Goal;
use Modules\Goals\Public\Enums\GoalStatus;
use Modules\Goals\Public\Http\Livewire\GoalsSummaryCard;
use Modules\Goals\Public\Services\GoalContributionWriter;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;

uses(RefreshDatabase::class);

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

    ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/closed-goal.xml',
        'sha256' => str_repeat('d', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);
});

function closedGoalCardFund(User $user, int $accountId, int $goalId, int $amountMinor, int $row): void
{
    $tx = Transaction::create([
        'user_id' => $user->id,
        'account_id' => $accountId,
        'type' => 'transfer_in',
        'posted_at' => CarbonImmutable::now()->subDays(10)->toDateString(),
        'booked_at' => CarbonImmutable::now()->subDays(10)->toDateString().' 12:00:00',
        'value_date' => CarbonImmutable::now()->subDays(10)->toDateString(),
        'amount_minor' => $amountMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $amountMinor,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Savings',
        'counterparty_normalized' => 'savings',
        'normalization_version' => 1,
        'source_format' => 'camt053',
        'import_run_id' => ImportRun::query()->where('user_id', $user->id)->value('id'),
        'source_row_index' => $row,
        'fingerprint' => str_pad((string) $row, 64, 'e'),
        'fingerprint_version' => 1,
    ]);

    app(GoalContributionWriter::class)->attribute($user, $goalId, $tx->id);
}

// A closed goal has no finish left to project, and the card holds three slots:
// letting it sort into one costs a goal the reader is still saving for.
it('lists the goals still running rather than the one already closed', function (): void {
    $closed = Goal::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Closed goal',
        'target_minor' => 20000,
        'start_date' => CarbonImmutable::now()->subDays(60)->toDateString(),
        'target_date' => CarbonImmutable::now()->addYear()->toDateString(),
        'status' => GoalStatus::Completed->value,
    ]);
    closedGoalCardFund($this->user, $this->account->id, $closed->id, 15000, 1);

    $row = 2;
    foreach (['First running goal', 'Second running goal', 'Third running goal'] as $name) {
        $goal = Goal::factory()->create([
            'user_id' => $this->user->id,
            'name' => $name,
            'target_minor' => 500000,
            'start_date' => CarbonImmutable::now()->subDays(60)->toDateString(),
            'target_date' => CarbonImmutable::now()->addYears(3)->toDateString(),
            'status' => GoalStatus::Active->value,
        ]);
        closedGoalCardFund($this->user, $this->account->id, $goal->id, 5000 + $row, $row);
        $row++;
    }

    Livewire::test(GoalsSummaryCard::class)
        ->assertOk()
        ->assertDontSee('Closed goal')
        ->assertSee('First running goal')
        ->assertSee('Second running goal')
        ->assertSee('Third running goal');
});
