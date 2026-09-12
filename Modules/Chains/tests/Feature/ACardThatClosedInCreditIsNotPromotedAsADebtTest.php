<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Chains\Internal\Services\CardStatementUpserter;
use Modules\Chains\Models\CardStatement;
use Modules\Chains\Public\Services\CardStatementQuery;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\StatementSummary;

uses(RefreshDatabase::class);

// The ICS reader signs each summary column by the Af/Bij marker printed beside
// it, so a card paid off past zero closes in CREDIT and statement_summaries
// stores a positive closing balance -- the reading
// TheSummaryReadTheFigureAndDiscardedItsDirectionTest pins on a committed
// fixture whose four columns read 93,04 Bij / 0,00 Bij / 43,71 Af / 49,33 Bij.
// Promotion took abs() of that figure and wrote the card owing the credit it
// holds, which is the same defect one writer further downstream.

/**
 * @return array{user: User, card: Account, bank: Account, run: ImportRun}
 */
function creditStatementFixture(string $username, int $closingMinor): array
{
    static $counter = 0;
    $counter++;

    $user = User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    $card = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'ICS '.$username,
        'slug' => 'credit-ics-'.$counter,
        'kind' => 'ics_card',
        'iban' => 'ICS-CREDIT-'.$counter,
        'default_currency' => 'EUR',
    ]);
    // The funder the tile names: with no account but the card itself,
    // nextSettlementForUser() answers null whatever the statement says, and the
    // assertions below would hold without the fix.
    $bank = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'Bank '.$username,
        'slug' => 'credit-bank-'.$counter,
        'kind' => 'bank',
        'iban' => 'NL57ASNB000000'.$counter,
        'default_currency' => 'EUR',
    ]);
    $run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'ics-pdf',
        'raw_file_path' => '/tmp/credit-'.$counter.'.pdf',
        'sha256' => str_pad((string) $counter, 64, 'c', STR_PAD_LEFT),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);
    StatementSummary::query()->create([
        'user_id' => $user->id,
        'import_run_id' => $run->id,
        'account_id' => $card->id,
        'iban_owner' => $card->iban,
        'statement_number' => 'STMT-CREDIT-'.$counter,
        'period_start' => '2026-02-20 00:00:00',
        'period_end' => '2026-02-20 00:00:00',
        'opening_balance_minor' => 9304,
        'opening_balance_currency' => 'EUR',
        'closing_balance_minor' => $closingMinor,
        'closing_balance_currency' => 'EUR',
        'entry_count' => 1,
    ]);

    return ['user' => $user, 'card' => $card, 'bank' => $bank, 'run' => $run];
}

it('promotes a credit closing balance as a credit rather than as the same figure owed', function (): void {
    $fixture = creditStatementFixture('credit-close', 4933);

    /** @var CardStatementUpserter $upserter */
    $upserter = $this->app->make(CardStatementUpserter::class);
    expect($upserter->upsertForImportRun($fixture['run']->id, $fixture['user']))->toBe(1);

    /** @var CardStatement $row */
    $row = CardStatement::query()->where('user_id', $fixture['user']->id)->firstOrFail();

    // abs() wrote 4933 into the open balance: a card holding EUR 49.33 stored
    // as one owing it, which BalanceAnchorResolver negates into a forecast
    // anchor EUR 98.66 below the position the card is actually in.
    expect($row->total_amount_minor)->toBe(4933);
    expect($row->open_balance_minor)->toBe(-4933);
});

it('quotes no next settlement for a statement that owes nothing', function (): void {
    $fixture = creditStatementFixture('credit-tile', 4933);

    /** @var CardStatementUpserter $upserter */
    $upserter = $this->app->make(CardStatementUpserter::class);
    $upserter->upsertForImportRun($fixture['run']->id, $fixture['user']);

    /** @var CardStatementQuery $query */
    $query = $this->app->make(CardStatementQuery::class);

    // Read off the state alone this drew "EUR 49.33 due", with an overdue
    // banner beside it once the deadline the statement prints had passed.
    expect($query->forecastTileForUser($fixture['user']))->toBeNull();
    expect($query->nextSettlementForUser($fixture['user']))->toBeNull();
});

it('still promotes an ordinary statement as the amount it owes', function (): void {
    $fixture = creditStatementFixture('credit-control', -50000);

    /** @var CardStatementUpserter $upserter */
    $upserter = $this->app->make(CardStatementUpserter::class);
    $upserter->upsertForImportRun($fixture['run']->id, $fixture['user']);

    /** @var CardStatement $row */
    $row = CardStatement::query()->where('user_id', $fixture['user']->id)->firstOrFail();

    expect($row->total_amount_minor)->toBe(-50000);
    expect($row->open_balance_minor)->toBe(50000);

    /** @var CardStatementQuery $query */
    $query = $this->app->make(CardStatementQuery::class);
    $tile = $query->forecastTileForUser($fixture['user']);

    expect($tile)->not->toBeNull();
    expect($tile->amount->toMinor())->toBe(50000);
    expect($tile->amount->currency())->toBe('EUR');

    $next = $query->nextSettlementForUser($fixture['user']);
    expect($next)->not->toBeNull();
    expect($next->accountId)->toBe($fixture['bank']->id);
});

it('repairs a statement already written owing the credit it holds', function (): void {
    $fixture = creditStatementFixture('credit-repair', 4933);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    // The row exactly as the old abs() wrote it, which is what a device that
    // imported a credit-closing statement before this fix has on disk.
    $db->connection()->table('card_statements')->insert([
        'user_id' => $fixture['user']->id,
        'account_id' => $fixture['card']->id,
        'import_run_id' => $fixture['run']->id,
        'period_start' => '2026-02-20 00:00:00',
        'period_end' => '2026-02-20 00:00:00',
        'due_date' => null,
        'total_amount_minor' => 4933,
        'open_balance_minor' => 4933,
        'currency' => 'EUR',
        'state' => 'open',
        'created_at' => '2026-03-15 00:00:00',
        'updated_at' => '2026-03-15 00:00:00',
    ]);

    $migration = require base_path('Modules/Chains/Database/Migrations/2026_09_12_000001_a_card_statement_that_closed_in_credit_was_promoted_as_a_debt.php');
    assert($migration instanceof Migration);
    $migration->up();

    /** @var CardStatement $row */
    $row = CardStatement::query()->where('user_id', $fixture['user']->id)->firstOrFail();
    expect($row->total_amount_minor)->toBe(4933);
    expect($row->open_balance_minor)->toBe(-4933);

    /** @var CardStatementQuery $query */
    $query = $this->app->make(CardStatementQuery::class);
    expect($query->forecastTileForUser($fixture['user']))->toBeNull();
});

it('leaves a statement a settlement has already moved exactly as it found it', function (): void {
    $fixture = creditStatementFixture('credit-settled', 4933);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    // Not the shape the old promotion wrote: the open balance no longer equals
    // the total, so nothing here can be reconstructed and the repair skips it.
    $db->connection()->table('card_statements')->insert([
        'user_id' => $fixture['user']->id,
        'account_id' => $fixture['card']->id,
        'import_run_id' => $fixture['run']->id,
        'period_start' => '2026-02-20 00:00:00',
        'period_end' => '2026-02-20 00:00:00',
        'due_date' => null,
        'total_amount_minor' => 4933,
        'open_balance_minor' => 2000,
        'currency' => 'EUR',
        'state' => 'partially_settled',
        'created_at' => '2026-03-15 00:00:00',
        'updated_at' => '2026-03-15 00:00:00',
    ]);

    $migration = require base_path('Modules/Chains/Database/Migrations/2026_09_12_000001_a_card_statement_that_closed_in_credit_was_promoted_as_a_debt.php');
    assert($migration instanceof Migration);
    $migration->up();

    /** @var CardStatement $row */
    $row = CardStatement::query()->where('user_id', $fixture['user']->id)->firstOrFail();
    expect($row->open_balance_minor)->toBe(2000);
    expect($row->state)->toBe('partially_settled');
});
