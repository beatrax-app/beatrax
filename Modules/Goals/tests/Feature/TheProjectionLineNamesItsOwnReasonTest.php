<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Goals\Internal\Http\Livewire\GoalsPage;
use Modules\Goals\Models\Goal;
use Modules\Goals\Public\Services\GoalContributionWriter;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;

// "Add contributions to see a projection" was the first branch of the chain and
// it asked only whether the SUM was zero, so it answered for three different
// states: a goal with nothing attributed, a goal whose attributions net out to
// a withdrawal, and a goal too young to measure. Two of those three have
// contributions, and being told to add some is a false sentence.

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-15 09:00:00');

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
        'raw_file_path' => '/tmp/projline.xml',
        'sha256' => str_repeat('9', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    $this->attribute = function (int $goalId, int $amountMinor, string $postedAt, int $seq): void {
        $transaction = Transaction::create([
            'user_id' => $this->user->id,
            'account_id' => $this->account->id,
            'type' => $amountMinor < 0 ? 'expense' : 'transfer_in',
            'posted_at' => $postedAt,
            'booked_at' => $postedAt.' 12:00:00',
            'value_date' => $postedAt,
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
            'fingerprint' => str_pad('projline'.$seq, 64, '0', STR_PAD_LEFT),
            'fingerprint_version' => 1,
        ]);

        app(GoalContributionWriter::class)->attribute($this->user, $goalId, $transaction->id);
    };
});

afterEach(fn () => CarbonImmutable::setTestNow(null));

it('asks for a first contribution only from a goal that has none', function (): void {
    Goal::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Leeg doel',
        'target_minor' => 120000,
        'start_date' => CarbonImmutable::now()->subDays(60)->toDateString(),
        'target_date' => CarbonImmutable::now()->addYearNoOverflow()->toDateString(),
        'status' => 'active',
    ]);

    Livewire::test(GoalsPage::class)
        ->assertOk()
        ->assertSee(Lang::get('goals::messages.projection.add_contributions'));
});

it('tells a goal whose attributions net out to a withdrawal that nothing recent came in', function (): void {
    $goal = Goal::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Uitgegeven doel',
        'target_minor' => 120000,
        'start_date' => CarbonImmutable::now()->subDays(60)->toDateString(),
        'target_date' => CarbonImmutable::now()->addYearNoOverflow()->toDateString(),
        'status' => 'active',
    ]);

    ($this->attribute)($goal->id, -2600, CarbonImmutable::now()->subDays(10)->toDateString(), 1);

    Livewire::test(GoalsPage::class)
        ->assertOk()
        ->assertSee(Lang::get('goals::messages.projection.no_recent_contributions'))
        ->assertDontSee(Lang::get('goals::messages.projection.add_contributions'));
});

it('tells a goal too young to measure that its history is short, contributions or not', function (): void {
    $goal = Goal::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Jong doel',
        'target_minor' => 120000,
        'start_date' => CarbonImmutable::now()->subDays(2)->toDateString(),
        'target_date' => CarbonImmutable::now()->addYearNoOverflow()->toDateString(),
        'status' => 'active',
    ]);

    ($this->attribute)($goal->id, -2600, CarbonImmutable::now()->subDay()->toDateString(), 2);

    Livewire::test(GoalsPage::class)
        ->assertOk()
        ->assertSee(Lang::get('goals::messages.projection.not_enough_history'))
        ->assertDontSee(Lang::get('goals::messages.projection.add_contributions'));
});

// The century clamp printed today + 36 500 days under "Est." — a date the rate
// never produced, presented with the same confidence as one it did.
it('says a finish is beyond dating rather than printing a clamped date as an estimate', function (): void {
    $goal = Goal::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Eeuwig doel',
        'target_minor' => PHP_INT_MAX,
        'start_date' => CarbonImmutable::now()->subDays(60)->toDateString(),
        'target_date' => CarbonImmutable::now()->addYearNoOverflow()->toDateString(),
        'status' => 'active',
    ]);

    ($this->attribute)($goal->id, 1, CarbonImmutable::now()->subDays(5)->toDateString(), 3);

    Livewire::test(GoalsPage::class)
        ->assertOk()
        ->assertSee(Lang::get('goals::messages.projection.too_far_to_date'))
        ->assertDontSee(CarbonImmutable::now()->addDays(36500)->isoFormat('D MMM YYYY'));
});
