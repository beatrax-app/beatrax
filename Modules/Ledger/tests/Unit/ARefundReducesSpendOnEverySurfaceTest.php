<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\Enums\MoneyFlow;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Ledger\Public\Services\SpendByCategoryQuery;
use Modules\Ledger\Public\Services\ThisPeriodAtAGlanceQuery;
use Modules\Ledger\Public\Services\TopCategoriesByPeriodQuery;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'refund-nets-spend',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN',
        'slug' => 'rns-asn',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456781',
        'default_currency' => 'EUR',
    ]);

    $this->run = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/rns.xml',
        'sha256' => str_repeat('c', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    $this->electronics = Category::create([
        'user_id' => null, 'name' => 'Electronics', 'slug' => 'rns-electronics', 'kind' => 'expense', 'display_order' => 1,
    ]);

    $this->period = new Period(
        start: CarbonImmutable::parse('2026-07-01'),
        endExclusive: CarbonImmutable::parse('2026-08-01'),
        label: 'July 2026',
    );

    // One EUR100.00 purchase and the EUR30.00 the shop sent back, same
    // category, same month. EUR70.00 actually left the account.
    refundNetsTx($this->user->id, $this->account->id, $this->run->id, -10000, $this->electronics->id, TransactionType::Expense);
    refundNetsTx($this->user->id, $this->account->id, $this->run->id, 3000, $this->electronics->id, TransactionType::Refund);
});

function refundNetsTx(int $userId, int $accountId, int $runId, int $settledMinor, int $categoryId, TransactionType $type): Transaction
{
    static $i = 400000;
    $i++;

    /** @var Transaction $tx */
    $tx = Transaction::create([
        'user_id' => $userId,
        'account_id' => $accountId,
        'type' => $type->value,
        'posted_at' => '2026-07-10',
        'booked_at' => '2026-07-10 12:00:00',
        'value_date' => '2026-07-10',
        'amount_minor' => $settledMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $settledMinor,
        'settled_currency' => 'EUR',
        'counterparty_name' => "RNS{$i}",
        'counterparty_normalized' => "rns{$i}",
        'normalization_version' => 1,
        'category_id' => $categoryId,
        'source_format' => 'camt053',
        'import_run_id' => $runId,
        'source_row_index' => $i,
        'fingerprint' => str_pad((string) $i, 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);

    return $tx;
}

it('nets the refund out of category spend', function (): void {
    $result = app(SpendByCategoryQuery::class)->forUserAndPeriodByCurrency($this->user->id, $this->period);

    expect($result[$this->electronics->id.'|EUR'])->toBe(7000);
});

// The dashboard's "Top spending" reads through SpendByCategoryQuery, and it
// showed the whole EUR100.00 beside a Reports row saying EUR70.00.
it('shows the netted figure in the dashboard top-spending list', function (): void {
    $rows = app(TopCategoriesByPeriodQuery::class)->for($this->user, $this->period, 'EUR')->rows;

    expect($rows)->toHaveCount(1);
    expect($rows[0]->spend->toMinor())->toBe(7000);
});

// Out, In and Net all left the refund out entirely: the month's Net read
// -EUR100.00 for a month in which EUR70.00 left.
it('counts the refund in the dashboard out and net tiles', function (): void {
    $summary = app(ThisPeriodAtAGlanceQuery::class)->for($this->user, $this->period, 'EUR');

    expect($summary->outflow->toMinor())->toBe(7000)
        ->and($summary->inflow->toMinor())->toBe(0)
        ->and($summary->net->toMinor())->toBe(-7000);
});

it('counts the refund in the per-currency tiles the same way', function (): void {
    $tiles = app(ThisPeriodAtAGlanceQuery::class)->forByCurrency($this->user, $this->period);

    expect($tiles)->toHaveCount(1);
    expect($tiles[0]->outflow->toMinor())->toBe(7000)
        ->and($tiles[0]->inflow->toMinor())->toBe(0)
        ->and($tiles[0]->net->toMinor())->toBe(-7000);
});

// A currency whose only activity in the period is a refund still has activity.
it('keeps a currency whose only row in the period is a refund', function (): void {
    $tiles = app(ThisPeriodAtAGlanceQuery::class)->forByCurrency($this->user, $this->period);
    $before = count($tiles);

    refundNetsTx($this->user->id, $this->account->id, $this->run->id, 2500, $this->electronics->id, TransactionType::Refund);
    Transaction::query()->where('settled_amount_minor', 2500)->update(['settled_currency' => 'USD', 'currency' => 'USD']);

    expect(app(ThisPeriodAtAGlanceQuery::class)->forByCurrency($this->user, $this->period))->toHaveCount($before + 1);
});

it('states the rule once, where both readers take it from', function (): void {
    expect(MoneyFlow::Spend->types())->toBe([TransactionType::Expense->value, TransactionType::Refund->value])
        ->and(MoneyFlow::Income->types())->toBe([TransactionType::Income->value])
        ->and(MoneyFlow::Net->types())->toBe([
            TransactionType::Expense->value,
            TransactionType::Income->value,
            TransactionType::Refund->value,
        ]);
});
