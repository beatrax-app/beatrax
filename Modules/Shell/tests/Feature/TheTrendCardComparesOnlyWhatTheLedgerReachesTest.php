<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Shell\Internal\Http\Livewire\SpendingTrendCard;

// Every figure this card draws is a difference. Taken unconditionally, the
// previous period was whatever PeriodQuery stepped back to, whether or not the
// ledger ever reached it: a reader whose first row was four days old was shown
// EUR 250,00 spent as +EUR 250,00, a full-amount rise in the rose colour that
// means "worth noticing", against a July their ledger does not cover.

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-24 09:00:00'));

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $this->db = $db;

    $this->user = User::create([
        'username' => 'trend-card-reach',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
    ]);
    $this->actingAs($this->user);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function trendReachSpend(DatabaseManager $db, int $userId, int $minor, string $postedAt): void
{
    $hex = bin2hex(random_bytes(5));
    $categoryId = $db->connection()->table('categories')->insertGetId([
        'user_id' => $userId, 'name' => 'Groceries', 'slug' => 'tcr-'.$hex,
        'kind' => 'expense', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId, 'name' => 'ASN '.$hex, 'slug' => 'tcr-'.$hex, 'kind' => 'bank',
        'iban' => 'NL00TCR'.strtoupper(substr($hex, 0, 8)), 'default_currency' => Currency::Eur->value,
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId, 'source_format' => 'asn-csv', 'raw_file_path' => '/tmp/tcr-'.$hex.'.csv',
        'sha256' => hash('sha256', 'tcr-'.$hex), 'uploaded_at' => '2026-01-01 00:00:00', 'status' => 'previewed',
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
    $db->connection()->table('transactions')->insert([
        'user_id' => $userId, 'account_id' => $accountId, 'import_run_id' => $runId, 'category_id' => $categoryId,
        'fingerprint' => hash('sha256', 'tcr-tx-'.$hex), 'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 12:00:00', 'value_date' => $postedAt,
        'amount_minor' => -$minor, 'currency' => Currency::Eur->value,
        'settled_amount_minor' => -$minor, 'settled_currency' => Currency::Eur->value,
        'counterparty_normalized' => 'tcr', 'counterparty_name' => 'TCR', 'normalization_version' => 3,
        'type' => 'expense', 'source_format' => 'asn-csv', 'source_row_index' => 1,
        'fingerprint_version' => 3, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
}

it('draws no rise against a month the ledger never reached', function (): void {
    $period = app(PeriodQuery::class)->current();
    $previousLabel = app(PeriodQuery::class)->previous($period)->label;

    trendReachSpend($this->db, $this->user->id, 25000, $period->start->addDays(3)->toDateString());

    Livewire::test(SpendingTrendCard::class)
        ->assertDontSee($previousLabel)
        ->assertDontSee('+')
        ->assertDontSee('data-not-converted', escape: false);
});

// The comparison must come back the moment the ledger covers the period being
// compared against, or the fix has simply silenced the card.
it('draws the comparison once the ledger reaches the previous period', function (): void {
    $periods = app(PeriodQuery::class);
    $period = $periods->current();
    $previous = $periods->previous($period);

    trendReachSpend($this->db, $this->user->id, 10000, $previous->start->addDays(2)->toDateString());
    trendReachSpend($this->db, $this->user->id, 25000, $period->start->addDays(3)->toDateString());

    Livewire::test(SpendingTrendCard::class)
        ->assertSee($previous->label)
        ->assertSee('Groceries');
});
