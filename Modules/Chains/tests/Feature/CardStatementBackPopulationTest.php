<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Modules\Chains\Models\CardStatement;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\StatementSummary;

/*
 * Feature-level coverage for the one-shot back-population migration.
 *
 * The migration walks `statement_summaries` joined to `accounts`,
 * filters on `accounts.kind = 'ics_card'`, and inserts a
 * `card_statements` row per surviving row via `insertOrIgnore`.
 *
 * The three test cases lock:
 *
 *   - sign convention (total_amount_minor preserves the negative
 *     closing-balance value; open_balance_minor is the absolute)
 *   - idempotency on re-run (insertOrIgnore against the UNIQUE
 *     (user_id, account_id, period_start, period_end))
 *   - ICS-kind filter (ASN statement_summaries do NOT back-populate)
 */

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    $this->conn = $db->connection();

    $this->user = User::query()->create([
        'username' => 'card-backpop',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    $this->icsAccount = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'ICS back-pop',
        'slug' => 'cbp-ics',
        'kind' => 'ics_card',
        'iban' => 'ICS-CARD',
        'default_currency' => 'EUR',
    ]);

    $this->asnAccount = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'ASN back-pop',
        'slug' => 'cbp-asn',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);

    $this->run = ImportRun::query()->create([
        'user_id' => $this->user->id,
        'source_format' => 'ics-pdf',
        'raw_file_path' => '/tmp/cbp.pdf',
        'sha256' => str_repeat('b', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);
});

/**
 * Invokes the back-population migration's `up()` method directly.
 *
 * Calling `Artisan::call('migrate')` is a no-op once the migration is
 * already recorded in `migrations` — the auto-migration on test boot
 * runs it once against an empty `statement_summaries`, so subsequent
 * Pest cases need to re-run the `up()` body directly to exercise the
 * idempotency contract and the test's seeded fixture rows.
 */
function runBackPopulation(): void
{
    $migration = require base_path('Modules/Chains/Database/Migrations/2026_05_16_010004_backpopulate_card_statements_from_statement_summaries.php');
    assert($migration instanceof Migration);
    $migration->up();
}

it('back-populates one card_statements row per ICS statement_summary, preserving the negative sign', function (): void {
    StatementSummary::query()->create([
        'user_id' => $this->user->id,
        'import_run_id' => $this->run->id,
        'account_id' => $this->icsAccount->id,
        'iban_owner' => 'ICS-CARD',
        'statement_number' => '2026-04',
        'period_start' => '2026-04-15 00:00:00',
        'period_end' => '2026-05-14 23:59:59',
        'opening_balance_minor' => 0,
        'opening_balance_currency' => 'EUR',
        'closing_balance_minor' => -84732,
        'closing_balance_currency' => 'EUR',
        'entry_count' => 23,
    ]);

    // Clear any prior auto-applied back-population rows for this user.
    CardStatement::query()->where('user_id', $this->user->id)->delete();

    runBackPopulation();

    $rows = CardStatement::query()->where('user_id', $this->user->id)->get();
    expect($rows)->toHaveCount(1);

    /** @var CardStatement $row */
    $row = $rows->first();
    expect($row->account_id)->toBe($this->icsAccount->id);
    expect($row->total_amount_minor)->toBe(-84732);
    expect($row->open_balance_minor)->toBe(84732);
    expect($row->state)->toBe('open');
    expect($row->period_start?->format('Y-m-d'))->toBe('2026-04-15');
    expect($row->period_end?->format('Y-m-d'))->toBe('2026-05-14');
});

it('is idempotent on re-run via the UNIQUE constraint and insertOrIgnore', function (): void {
    StatementSummary::query()->create([
        'user_id' => $this->user->id,
        'import_run_id' => $this->run->id,
        'account_id' => $this->icsAccount->id,
        'iban_owner' => 'ICS-CARD',
        'statement_number' => '2026-04',
        'period_start' => '2026-04-15 00:00:00',
        'period_end' => '2026-05-14 23:59:59',
        'opening_balance_minor' => 0,
        'opening_balance_currency' => 'EUR',
        'closing_balance_minor' => -84732,
        'closing_balance_currency' => 'EUR',
        'entry_count' => 23,
    ]);

    CardStatement::query()->where('user_id', $this->user->id)->delete();

    runBackPopulation();
    runBackPopulation();
    runBackPopulation();

    expect(CardStatement::query()->where('user_id', $this->user->id)->count())->toBe(1);
});

it('does not back-populate statement_summaries whose account is not ICS-kind', function (): void {
    $asnRun = ImportRun::query()->create([
        'user_id' => $this->user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/cbp-asn.csv',
        'sha256' => str_repeat('q', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    StatementSummary::query()->create([
        'user_id' => $this->user->id,
        'import_run_id' => $asnRun->id,
        'account_id' => $this->asnAccount->id,
        'iban_owner' => 'NL57ASNB0123456789',
        'statement_number' => '2026-05',
        'period_start' => '2026-05-01 00:00:00',
        'period_end' => '2026-05-31 23:59:59',
        'opening_balance_minor' => 1000_00,
        'opening_balance_currency' => 'EUR',
        'closing_balance_minor' => 800_00,
        'closing_balance_currency' => 'EUR',
        'entry_count' => 12,
    ]);

    CardStatement::query()->where('user_id', $this->user->id)->delete();

    runBackPopulation();

    // No ICS statement_summary exists → no card_statements row should land.
    expect(CardStatement::query()->where('user_id', $this->user->id)->count())->toBe(0);
});
