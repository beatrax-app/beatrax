<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Ledger\Public\Services\ThisPeriodAtAGlanceQuery;

// incomeForPeriod() names a value transactions.type stores. Spelled as a bare
// string it fails silently: a renamed case leaves the query valid, the sum
// comes back 0, and an income tile that has stopped matching is
// indistinguishable from a period with no income.

beforeEach(function (): void {
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-15 12:00:00'));

    /** @var Account $account */
    $account = Account::query()
        ->where('user_id', $this->fixtureUser->id)
        ->where('iban', 'NL57ASNB0123456789')
        ->firstOrFail();
    $this->asnAccount = $account;

    $this->run = $this->makeImportRun($this->fixtureUser);

    $this->period = new Period(
        start: CarbonImmutable::parse('2026-05-01'),
        endExclusive: CarbonImmutable::parse('2026-06-01'),
        label: 'May 2026',
    );
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('sums a row stored under the type the owning enum names', function (): void {
    $this->makeTransaction($this->fixtureUser, $this->asnAccount, $this->run, [
        'type' => TransactionType::Income->value,
        'amount_minor' => 120000,
        'settled_amount_minor' => 120000,
        'posted_at' => '2026-05-05',
        'booked_at' => '2026-05-05 12:00:00',
        'counterparty_name' => 'Employer NV',
    ]);

    /** @var ThisPeriodAtAGlanceQuery $query */
    $query = $this->app->make(ThisPeriodAtAGlanceQuery::class);

    expect($query->incomeForPeriod($this->fixtureUser, $this->period))->toBe(120000);
});

// Every other case shares the column, so a spelling that drifted onto one of
// them would silently widen the sum instead of emptying it.
it('sums none of the other cases the same column accepts', function (): void {
    $offset = 0;
    foreach (TransactionType::cases() as $type) {
        if ($type === TransactionType::Income) {
            continue;
        }

        $offset++;
        $this->makeTransaction($this->fixtureUser, $this->asnAccount, $this->run, [
            'type' => $type->value,
            'amount_minor' => 1000 * $offset,
            'settled_amount_minor' => 1000 * $offset,
            'posted_at' => '2026-05-0'.$offset,
            'booked_at' => '2026-05-0'.$offset.' 12:00:00',
            'counterparty_name' => 'Vocabulary '.$type->value,
        ]);
    }

    /** @var ThisPeriodAtAGlanceQuery $query */
    $query = $this->app->make(ThisPeriodAtAGlanceQuery::class);

    expect($query->incomeForPeriod($this->fixtureUser, $this->period))->toBe(0);
});

// The table carries a CHECK trigger naming its own vocabulary. If a case ever
// stops matching, the insert aborts here rather than in a tile gone quietly
// to zero.
it('stores every case the transactions type trigger accepts', function (): void {
    $offset = 0;
    foreach (TransactionType::cases() as $type) {
        $offset++;
        $this->makeTransaction($this->fixtureUser, $this->asnAccount, $this->run, [
            'type' => $type->value,
            'amount_minor' => 500 * $offset,
            'settled_amount_minor' => 500 * $offset,
            'posted_at' => '2026-05-0'.$offset,
            'booked_at' => '2026-05-0'.$offset.' 12:00:00',
            'counterparty_name' => 'Trigger '.$type->value,
        ]);
    }

    expect(DB::table('transactions')->where('user_id', $this->fixtureUser->id)->count())
        ->toBe(count(TransactionType::cases()));
});
