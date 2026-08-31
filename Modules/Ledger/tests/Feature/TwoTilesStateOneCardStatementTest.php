<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Chains\Public\Services\CardStatementQuery;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Services\ThisPeriodAtAGlanceQuery;

// The forecast highlights and the position tile name the same statement on the
// same day. The amount was built twice — once deducting the credits carried
// into it, once off the raw open balance — so the two screens disagreed by the
// whole credit while both quoted the same due date.

function twoTilesAccount(User $user, string $kind, string $iban): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'two-tiles '.$kind,
        'slug' => 'two-tiles-'.$kind.'-'.bin2hex(random_bytes(3)),
        'kind' => $kind,
        'iban' => $iban,
        'default_currency' => 'EUR',
    ]);
}

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $this->db = $db;

    $this->user = User::query()->create([
        'username' => 'two-tiles-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);

    $this->bank = twoTilesAccount($this->user, 'bank', 'NL57ASNB0987654321');
    $this->ics = twoTilesAccount($this->user, 'ics_card', 'ICS-TWO-TILES');

    $now = CarbonImmutable::now()->toDateTimeString();

    $this->closedId = (int) $db->connection()->table('card_statements')->insertGetId([
        'user_id' => $this->user->id,
        'account_id' => $this->ics->id,
        'import_run_id' => null,
        'period_start' => '2026-03-18 00:00:00',
        'period_end' => '2026-04-17 00:00:00',
        'total_amount_minor' => -30000,
        'open_balance_minor' => 0,
        'currency' => 'EUR',
        'state' => 'overpaid',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $this->openId = (int) $db->connection()->table('card_statements')->insertGetId([
        'user_id' => $this->user->id,
        'account_id' => $this->ics->id,
        'import_run_id' => null,
        'period_start' => '2026-04-18 00:00:00',
        'period_end' => '2026-05-17 00:00:00',
        'total_amount_minor' => -145000,
        'open_balance_minor' => 145000,
        'currency' => 'EUR',
        'state' => 'open',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $db->connection()->table('card_statement_credits')->insert([
        'user_id' => $this->user->id,
        'from_statement_id' => $this->closedId,
        'to_statement_id' => $this->openId,
        'amount_minor' => 20000,
        'currency' => 'EUR',
        'reason' => 'overpayment',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
});

it('quotes one amount and one date for a statement carrying a credit', function (): void {
    /** @var CardStatementQuery $chains */
    $chains = app(CardStatementQuery::class);
    /** @var ThisPeriodAtAGlanceQuery $glance */
    $glance = app(ThisPeriodAtAGlanceQuery::class);

    $highlights = $chains->nextSettlementForUser($this->user);
    $tile = $glance->nextIcsSettlement($this->user);

    expect($highlights)->not->toBeNull();
    expect($tile)->not->toBeNull();
    expect($tile->statementId)->toBe($highlights->statementId);

    // 145000 owed less the 20000 already paid in: the payment the resolver will
    // settle this statement to zero with, which is what leaves the bank.
    expect($highlights->amount->toMinor())->toBe(125000);
    expect($tile->amount->toMinor())->toBe($highlights->amount->toMinor());
    expect($tile->amount->currency())->toBe($highlights->amount->currency());
    expect($tile->dueDate->toDateString())->toBe($highlights->dueDate->toDateString());
});

it('quotes one amount when the statement carries no credit at all', function (): void {
    $this->db->connection()->table('card_statement_credits')->where('user_id', $this->user->id)->delete();

    /** @var CardStatementQuery $chains */
    $chains = app(CardStatementQuery::class);
    /** @var ThisPeriodAtAGlanceQuery $glance */
    $glance = app(ThisPeriodAtAGlanceQuery::class);

    $highlights = $chains->nextSettlementForUser($this->user);
    $tile = $glance->nextIcsSettlement($this->user);

    expect($highlights)->not->toBeNull();
    expect($tile)->not->toBeNull();
    expect($highlights->amount->toMinor())->toBe(145000);
    expect($tile->amount->toMinor())->toBe($highlights->amount->toMinor());
});

// The tile draws for a reader with no account to pay from; the highlights line
// cannot, because it has to name the funder. That asymmetry is the one place
// the two are allowed to differ, and folding the reads must not lose it.
it('still draws the tile for a reader whose only account is the card', function (): void {
    $this->db->connection()->table('accounts')->where('id', $this->bank->id)->delete();

    /** @var ThisPeriodAtAGlanceQuery $glance */
    $glance = app(ThisPeriodAtAGlanceQuery::class);

    $tile = $glance->nextIcsSettlement($this->user);

    expect($tile)->not->toBeNull();
    expect($tile->amount->toMinor())->toBe(125000);
    expect(app(CardStatementQuery::class)->nextSettlementForUser($this->user))->toBeNull();
});
