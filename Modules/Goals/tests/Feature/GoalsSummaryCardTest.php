<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Goals\Internal\Http\Livewire\GoalsSummaryCard;
use Modules\Goals\Models\Goal;
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

    $this->run = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/summary.xml',
        'sha256' => str_repeat('d', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);
});

function summaryCardCredit(User $user, int $accountId, int $amountMinor): Transaction
{
    return Transaction::create([
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
        'source_row_index' => 1,
        'fingerprint' => str_repeat('e', 64),
        'fingerprint_version' => 1,
    ]);
}

it('renders the summary card and sorts goals without a projection last', function (): void {
    // Two goals exercise the null-last comparator: only the funded one can carry
    // a projection, so the untouched goal's null has to sort behind it.
    $funded = Goal::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Funded goal',
        'target_minor' => 100000,
        'start_date' => CarbonImmutable::now()->subDays(30)->toDateString(),
        'target_date' => CarbonImmutable::now()->addYear()->toDateString(),
        'status' => 'active',
    ]);
    Goal::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Untouched goal',
        'target_minor' => 50000,
        'start_date' => CarbonImmutable::now()->subDays(30)->toDateString(),
        'status' => 'active',
    ]);

    $tx = summaryCardCredit($this->user, $this->account->id, 20000);
    app(GoalContributionWriter::class)->attribute($this->user, $funded->id, $tx->id);

    Livewire::test(GoalsSummaryCard::class)
        ->assertOk()
        ->assertSee('Funded goal')
        ->assertSee('Untouched goal');
});
