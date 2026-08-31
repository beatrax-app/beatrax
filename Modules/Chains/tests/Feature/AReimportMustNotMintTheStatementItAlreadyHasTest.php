<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Modules\Chains\Models\CardStatement;
use Modules\Chains\Public\Contracts\UpsertsCardStatements;
use Modules\Core\Models\User;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\StatementSummary;
use Modules\Ledger\Models\Transaction;

// The period is derived from posted_at now, and UNIQUE(user_id, account_id,
// period_start, period_end) is the whole of deciding whether a fresh read of a
// statement matches the row already stored for it. Every statement written
// before the change carries the booked-derived pair, so left alone each one is
// minted a second time and the stale row stays open forever.
/**
 * @link ../../Database/Migrations/2026_08_30_000001_reopen_every_card_statement_on_the_day_it_starts_billing.php
 */
function runPeriodRepair(): void
{
    $migration = require base_path('Modules/Chains/Database/Migrations/2026_08_30_000001_reopen_every_card_statement_on_the_day_it_starts_billing.php');
    assert($migration instanceof Migration);
    $migration->up();
}

// Importing the identical file twice is refused by the sha256 dedup, so the
// second read is spelled the way a device actually meets it: ICS regenerates
// the PDF for the same statement, the bytes differ, and the pipeline confirms a
// new run whose summary carries the period the current adapter derives.
function readTheSameStatementAgain(User $user, int $accountId, string $periodStart, string $periodEnd): void
{
    $run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'ics-pdf',
        'raw_file_path' => '/tmp/reread-'.bin2hex(random_bytes(4)).'.pdf',
        'sha256' => str_repeat(bin2hex(random_bytes(1)), 32),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'confirmed',
    ]);

    StatementSummary::query()->create([
        'user_id' => $user->id,
        'import_run_id' => $run->id,
        'account_id' => $accountId,
        'iban_owner' => 'ICS-CARD',
        'period_start' => $periodStart,
        'period_end' => $periodEnd,
        'closing_balance_minor' => -84732,
        'closing_balance_currency' => 'EUR',
        'entry_count' => 23,
    ]);

    /** @var UpsertsCardStatements $upserter */
    $upserter = app(UpsertsCardStatements::class);
    $upserter->upsertForImportRun((int) $run->id, $user);
}

// The pair IcsPdfAdapter derived from min/max booked_at for this fixture,
// written back over both tables so the migration meets the rows a device that
// imported before the fix is holding.
function restoreTheBookedDerivedPeriod(DatabaseManager $db, User $user): void
{
    $booked = ['period_start' => '2026-04-17 00:00:00', 'period_end' => '2026-05-14 00:00:00'];

    $db->connection()->table('card_statements')->where('user_id', $user->id)->update($booked);
    $db->connection()->table('statement_summaries')->where('user_id', $user->id)->update($booked + [
        'opening_balance_date' => '2026-04-17 00:00:00',
        'closing_balance_date' => '2026-05-14 00:00:00',
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-05-20 09:00:00');

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;

    /** @var array{user: User} $seed */
    $seed = $this->seedFixtureUserAndAccount();
    $this->user = $seed['user'];
    $this->actingAs($this->user);

    /** @var RunsImports $importer */
    $importer = $this->app->make(RunsImports::class);
    $importer->runAndConfirm(
        base_path('Modules/Chains/tests/fixtures/scenario-1/ics-statement.pdf'),
        'ics-pdf',
        $this->user,
    );

    /** @var CardStatement $statement */
    $statement = CardStatement::query()->where('user_id', $this->user->id)->sole();
    $this->cardAccountId = $statement->account_id;
    $this->derivedStart = '2026-04-15 00:00:00';
    $this->derivedEnd = '2026-05-13 00:00:00';

    restoreTheBookedDerivedPeriod($this->db, $this->user);
});

it('moves a stored statement onto the days it bills, in both tables that hold the pair', function (): void {
    runPeriodRepair();

    /** @var CardStatement $statement */
    $statement = CardStatement::query()->where('user_id', $this->user->id)->sole();
    expect($statement->period_start?->format('Y-m-d'))->toBe('2026-04-15');
    expect($statement->period_end?->format('Y-m-d'))->toBe('2026-05-13');

    /** @var StatementSummary $summary */
    $summary = StatementSummary::query()->where('user_id', $this->user->id)->sole();
    expect($summary->period_start?->format('Y-m-d'))->toBe('2026-04-15');
    expect($summary->period_end?->format('Y-m-d'))->toBe('2026-05-13');
    expect($summary->opening_balance_date?->format('Y-m-d'))->toBe('2026-04-15');
    expect($summary->closing_balance_date?->format('Y-m-d'))->toBe('2026-05-13');
});

// The importer writes the day at midnight through CarbonImmutable, and the
// UNIQUE compares the stored spelling: a bare '2026-04-15' would repair the
// reader's screen and still mint the second row.
it('writes the day in the shape the importer writes it, which is the whole of matching the row', function (): void {
    runPeriodRepair();

    $stored = $this->db->connection()->table('card_statements')
        ->where('user_id', $this->user->id)
        ->first(['period_start', 'period_end']);

    expect($stored->period_start)->toBe($this->derivedStart);
    expect($stored->period_end)->toBe($this->derivedEnd);
});

it('lets a second read of the statement match the row already stored', function (): void {
    runPeriodRepair();

    readTheSameStatementAgain($this->user, $this->cardAccountId, $this->derivedStart, $this->derivedEnd);

    $statements = CardStatement::query()->where('user_id', $this->user->id)->get();
    expect($statements)->toHaveCount(1);
    expect($statements->first()->period_start?->format('Y-m-d'))->toBe('2026-04-15');
});

// The failure the migration exists to prevent, with the migration taken away.
it('mints a second statement when the stored period is left booked-derived', function (): void {
    readTheSameStatementAgain($this->user, $this->cardAccountId, $this->derivedStart, $this->derivedEnd);

    expect(CardStatement::query()->where('user_id', $this->user->id)->get())->toHaveCount(2);
});

// ResolveChainLinksJob calls upsertForUser() on every pass, over every summary
// there has ever been. Repairing the statement without its summary would put
// the stale period back on the very next chain resolution.
it('repairs the summary too, so the healing pass does not put the old period back', function (): void {
    runPeriodRepair();

    /** @var UpsertsCardStatements $upserter */
    $upserter = $this->app->make(UpsertsCardStatements::class);
    $upserter->upsertForUser($this->user);

    expect(CardStatement::query()->where('user_id', $this->user->id)->get())->toHaveCount(1);
});

it('puts the old period back on the healing pass when only the statement was repaired', function (): void {
    $this->db->connection()->table('card_statements')->where('user_id', $this->user->id)->update([
        'period_start' => $this->derivedStart,
        'period_end' => $this->derivedEnd,
    ]);

    /** @var UpsertsCardStatements $upserter */
    $upserter = $this->app->make(UpsertsCardStatements::class);
    $upserter->upsertForUser($this->user);

    expect(CardStatement::query()->where('user_id', $this->user->id)->get())->toHaveCount(2);
});

it('leaves a statement whose transactions were deleted exactly as found', function (): void {
    Transaction::query()->where('user_id', $this->user->id)->delete();

    runPeriodRepair();

    /** @var CardStatement $statement */
    $statement = CardStatement::query()->where('user_id', $this->user->id)->sole();
    expect($statement->period_start?->format('Y-m-d'))->toBe('2026-04-17');
    expect($statement->period_end?->format('Y-m-d'))->toBe('2026-05-14');
});

// down() restores nothing, so a rollback followed by a second up() is the one
// way this runs twice. The second pass must not read the repaired period as a
// booked one and narrow the statement onto a subset of its own charges.
it('changes nothing on a second pass', function (): void {
    runPeriodRepair();
    runPeriodRepair();

    /** @var CardStatement $statement */
    $statement = CardStatement::query()->where('user_id', $this->user->id)->sole();
    expect($statement->period_start?->format('Y-m-d'))->toBe('2026-04-15');
    expect($statement->period_end?->format('Y-m-d'))->toBe('2026-05-13');
});

it('leaves a statement on an account that is not a card alone', function (): void {
    $bank = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'Bank',
        'slug' => 'period-repair-bank',
        'kind' => 'bank',
        'iban' => 'NL91ABNA0417164300',
        'default_currency' => 'EUR',
    ]);
    $run = ImportRun::query()->create([
        'user_id' => $this->user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/period-repair.xml',
        'sha256' => str_repeat('c', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'confirmed',
    ]);
    StatementSummary::query()->create([
        'user_id' => $this->user->id,
        'import_run_id' => $run->id,
        'account_id' => $bank->id,
        'iban_owner' => 'NL91ABNA0417164300',
        'period_start' => '2026-04-17 00:00:00',
        'period_end' => '2026-05-14 00:00:00',
        'entry_count' => 0,
    ]);

    runPeriodRepair();

    /** @var StatementSummary $summary */
    $summary = StatementSummary::query()->where('account_id', $bank->id)->sole();
    expect($summary->period_start?->format('Y-m-d'))->toBe('2026-04-17');
    expect($summary->period_end?->format('Y-m-d'))->toBe('2026-05-14');
});
