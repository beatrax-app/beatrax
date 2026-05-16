<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Chains\Models\CardStatement;
use Modules\Chains\Public\Dto\CardStatementForecastTile;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Services\ThisPeriodAtAGlanceQuery;

/*
 * Dashboard "Next ICS settlement" tile (D-99 / D-100) + failed-job
 * toast (D-103 / issue #1 + #8) tests. Exercises:
 *
 *   - ThisPeriodAtAGlanceQuery::nextIcsSettlement() returns
 *     CardStatementForecastTile when an open card_statement exists
 *     for an ics_card account.
 *   - Returns null when no open / partially_settled statement exists
 *     (tile hides).
 *   - Forecast amount = open_balance_minor (D-100); due_date =
 *     period_end + 5 days.
 *   - Dashboard renders the tile when non-null; hides when null.
 *   - Failed-job toast renders when chain_resolution_runs.status='failed'
 *     for the user (issue #1 + #8 — reads audit table, NEVER
 *     failed_jobs.payload LIKE substring).
 *   - Cross-user isolation for the toast (issue #8).
 */

function nistUser(string $email): User
{
    return User::query()->create([
        'email' => $email,
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
}

function nistImportRun(User $user, string $sha): ImportRun
{
    return ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'ics-pdf',
        'raw_file_path' => '/tmp/nist.pdf',
        'sha256' => $sha,
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);
}

function nistIcsAccount(User $user, string $slug): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'nist '.$slug,
        'slug' => $slug,
        'kind' => 'ics_card',
        'iban' => 'ICS-CARD',
        'default_currency' => 'EUR',
    ]);
}

beforeEach(function (): void {
    $this->user = nistUser('next-ics-tile@diederik.test');
    $this->otherUser = nistUser('next-ics-tile-other@diederik.test');
    $this->icsAccount = nistIcsAccount($this->user, 'nist-ics');
    $this->run = nistImportRun($this->user, str_repeat('n', 64));

    // Required so the dashboard route does NOT bounce to /imports/new
    // via the first-run redirect. Seed a tiny ASN account + a single
    // transaction so isFirstRun returns false.
    $this->asn = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'nist asn',
        'slug' => 'nist-asn',
        'kind' => 'asn',
        'iban' => 'NL57ASNB9999999999',
        'default_currency' => 'EUR',
    ]);
    Transaction::query()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->asn->id,
        'type' => 'expense',
        'posted_at' => '2026-05-10',
        'booked_at' => '2026-05-10 12:00:00',
        'value_date' => '2026-05-10',
        'amount_minor' => -1000,
        'currency' => 'EUR',
        'settled_amount_minor' => -1000,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Seed',
        'counterparty_normalized' => 'seed',
        'normalization_version' => 3,
        'source_format' => 'asn-csv',
        'import_run_id' => $this->run->id,
        'source_row_index' => 1,
        'fingerprint' => str_pad('nist-seed', 64, 'c', STR_PAD_LEFT),
        'fingerprint_version' => 3,
    ]);
});

it('nextIcsSettlement returns a CardStatementForecastTile when an open card_statement exists', function (): void {
    CardStatement::query()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->icsAccount->id,
        'import_run_id' => $this->run->id,
        'period_start' => '2026-04-01 00:00:00',
        'period_end' => '2026-04-30 23:59:59',
        'total_amount_minor' => -52347,
        'open_balance_minor' => 52347,
        'state' => 'open',
    ]);

    /** @var ThisPeriodAtAGlanceQuery $query */
    $query = $this->app->make(ThisPeriodAtAGlanceQuery::class);
    $tile = $query->nextIcsSettlement($this->user);

    expect($tile)->toBeInstanceOf(CardStatementForecastTile::class);
    expect((int) $tile->amount->toMinor())->toBe(52347);
    // due_date is period_end + 5 days (D-100).
    expect($tile->dueDate->format('Y-m-d'))->toBe('2026-05-05');
    expect($tile->state)->toBe('open');
});

it('nextIcsSettlement returns null when no open card_statement exists', function (): void {
    // Seed a SETTLED statement — should be ignored.
    CardStatement::query()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->icsAccount->id,
        'import_run_id' => $this->run->id,
        'period_start' => '2026-04-01 00:00:00',
        'period_end' => '2026-04-30 23:59:59',
        'total_amount_minor' => -52347,
        'open_balance_minor' => 0,
        'state' => 'settled',
    ]);

    /** @var ThisPeriodAtAGlanceQuery $query */
    $query = $this->app->make(ThisPeriodAtAGlanceQuery::class);

    expect($query->nextIcsSettlement($this->user))->toBeNull();
});

it('nextIcsSettlement returns the most recent open card_statement when multiple are open', function (): void {
    CardStatement::query()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->icsAccount->id,
        'import_run_id' => $this->run->id,
        'period_start' => '2026-03-01 00:00:00',
        'period_end' => '2026-03-31 23:59:59',
        'total_amount_minor' => -10000,
        'open_balance_minor' => 10000,
        'state' => 'open',
    ]);
    $newer = CardStatement::query()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->icsAccount->id,
        'import_run_id' => $this->run->id,
        'period_start' => '2026-04-01 00:00:00',
        'period_end' => '2026-04-30 23:59:59',
        'total_amount_minor' => -70000,
        'open_balance_minor' => 70000,
        'state' => 'partially_settled',
    ]);

    /** @var ThisPeriodAtAGlanceQuery $query */
    $query = $this->app->make(ThisPeriodAtAGlanceQuery::class);
    $tile = $query->nextIcsSettlement($this->user);

    expect($tile)->not->toBeNull();
    expect($tile->statementId)->toBe((int) $newer->id);
    expect($tile->state)->toBe('partially_settled');
    expect((int) $tile->amount->toMinor())->toBe(70000);
});

it('nextIcsSettlement isolates by user — never reads another user\'s open card_statement', function (): void {
    $otherIcs = nistIcsAccount($this->otherUser, 'nist-ics-other');

    CardStatement::query()->create([
        'user_id' => $this->otherUser->id,
        'account_id' => $otherIcs->id,
        'import_run_id' => null,
        'period_start' => '2026-04-01 00:00:00',
        'period_end' => '2026-04-30 23:59:59',
        'total_amount_minor' => -99999,
        'open_balance_minor' => 99999,
        'state' => 'open',
    ]);

    /** @var ThisPeriodAtAGlanceQuery $query */
    $query = $this->app->make(ThisPeriodAtAGlanceQuery::class);

    expect($query->nextIcsSettlement($this->user))->toBeNull();
});

it('Dashboard renders the Next ICS settlement tile when nextIcsSettlement returns non-null', function (): void {
    CardStatement::query()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->icsAccount->id,
        'import_run_id' => $this->run->id,
        'period_start' => '2026-04-01 00:00:00',
        'period_end' => '2026-04-30 23:59:59',
        'total_amount_minor' => -52347,
        'open_balance_minor' => 52347,
        'state' => 'open',
    ]);

    $this->actingAs($this->user)
        ->get('/')
        ->assertOk()
        ->assertSeeText('Next ICS settlement')
        ->assertSeeText('due ~');
});

it('Dashboard hides the Next ICS settlement tile when nextIcsSettlement returns null', function (): void {
    // No card_statement seeded → tile hidden.
    $this->actingAs($this->user)
        ->get('/')
        ->assertOk()
        ->assertDontSeeText('Next ICS settlement');
});

it('failed-job toast renders when chain_resolution_runs.status=failed for the user (issue #1 + #8 audit-table contract)', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $now = CarbonImmutable::now()->toDateTimeString();
    $db->connection()->table('chain_resolution_runs')->insert([
        'user_id' => $this->user->id,
        'status' => 'failed',
        'last_error' => 'TestException: something went wrong',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $this->actingAs($this->user)
        ->get('/')
        ->assertOk()
        ->assertSeeText('Chain resolution failed')
        ->assertSeeText('Open Horizon');
});

it('failed-job toast hidden when chain_resolution_runs has no failed rows for the user (cross-user — issue #8 substring-attack guard)', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $now = CarbonImmutable::now()->toDateTimeString();
    $db->connection()->table('chain_resolution_runs')->insert([
        'user_id' => $this->otherUser->id,
        'status' => 'failed',
        'last_error' => 'OtherUserError',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $this->actingAs($this->user)
        ->get('/')
        ->assertOk()
        ->assertDontSeeText('Chain resolution failed');
});

it('failed-job toast hidden when chain_resolution_runs has no rows at all', function (): void {
    $this->actingAs($this->user)
        ->get('/')
        ->assertOk()
        ->assertDontSeeText('Chain resolution failed');
});
