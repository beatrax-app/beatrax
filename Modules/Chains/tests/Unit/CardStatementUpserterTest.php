<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Chains\Internal\Services\CardStatementUpserter;
use Modules\Chains\Models\CardStatement;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\StatementSummary;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $summaryOverrides
 * @return array{user: User, account: Account, run: ImportRun, summary: StatementSummary}
 */
function seedIcsSummary(
    string $username,
    string $accountKind,
    int $closingBalanceMinor,
    ?string $periodStart,
    ?string $periodEnd,
    array $summaryOverrides = [],
): array {
    static $counter = 0;
    $counter++;
    $user = User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    $account = Account::query()->create([
        'user_id' => $user->id,
        'name' => sprintf('Account-%d', $counter),
        'slug' => sprintf('acc-%d', $counter),
        'kind' => $accountKind,
        'iban' => sprintf('IBAN-%d', $counter),
        'default_currency' => 'EUR',
    ]);
    $run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => $accountKind === 'ics_card' ? 'ics-pdf' : 'asn-csv',
        'raw_file_path' => sprintf('/tmp/fixture-%d', $counter),
        'sha256' => str_pad((string) $counter, 64, 's', STR_PAD_LEFT),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);
    $summary = StatementSummary::query()->create(array_merge([
        'user_id' => $user->id,
        'import_run_id' => $run->id,
        'account_id' => $account->id,
        'iban_owner' => $account->iban,
        'statement_number' => sprintf('STMT-%d', $counter),
        'period_start' => $periodStart,
        'period_end' => $periodEnd,
        'opening_balance_minor' => 0,
        'opening_balance_currency' => 'EUR',
        'closing_balance_minor' => $closingBalanceMinor,
        'closing_balance_currency' => 'EUR',
        'entry_count' => 1,
    ], $summaryOverrides));

    return ['user' => $user, 'account' => $account, 'run' => $run, 'summary' => $summary];
}

beforeEach(function (): void {
    // Clear any auto-applied back-population rows from the
    // 2026_05_16_010004 migration so this test owns the table state.
    CardStatement::query()->delete();

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;

    /** @var CardStatementUpserter $upserter */
    $upserter = $this->app->make(CardStatementUpserter::class);
    $this->upserter = $upserter;
});

it('promotes a fresh ICS statement_summary into a card_statements row', function (): void {
    $fixture = seedIcsSummary(
        username: 'upsert-ics',
        accountKind: 'ics_card',
        closingBalanceMinor: -12345,
        periodStart: '2026-04-01 00:00:00',
        periodEnd: '2026-04-30 23:59:59',
    );

    $countBefore = CardStatement::query()->where('user_id', $fixture['user']->id)->count();
    expect($countBefore)->toBe(0);

    $inserted = $this->upserter->upsertForImportRun($fixture['run']->id, $fixture['user']);

    $countAfter = CardStatement::query()->where('user_id', $fixture['user']->id)->count();
    $delta = $countAfter - $countBefore;
    expect($delta)->toBe(1);
    expect($inserted)->toBe(1);

    /** @var CardStatement $row */
    $row = CardStatement::query()->where('user_id', $fixture['user']->id)->firstOrFail();
    expect($row->account_id)->toBe($fixture['account']->id);
    expect($row->import_run_id)->toBe($fixture['run']->id);
    expect($row->total_amount_minor)->toBe(-12345);
    expect($row->open_balance_minor)->toBe(12345);
    expect($row->state)->toBe('open');
    expect($row->period_start?->format('Y-m-d'))->toBe('2026-04-01');
    expect($row->period_end?->format('Y-m-d'))->toBe('2026-04-30');
});

it('is idempotent — re-running produces zero new rows', function (): void {
    $fixture = seedIcsSummary(
        username: 'upsert-ics-idem',
        accountKind: 'ics_card',
        closingBalanceMinor: -50000,
        periodStart: '2026-05-01 00:00:00',
        periodEnd: '2026-05-31 23:59:59',
    );

    $first = $this->upserter->upsertForImportRun($fixture['run']->id, $fixture['user']);
    expect($first)->toBe(1);
    expect(CardStatement::query()->where('user_id', $fixture['user']->id)->count())->toBe(1);

    $second = $this->upserter->upsertForImportRun($fixture['run']->id, $fixture['user']);
    expect($second)->toBe(0);
    expect(CardStatement::query()->where('user_id', $fixture['user']->id)->count())->toBe(1);
});

it('does NOT mutate an existing card_statements row state column on re-run (firstOrCreate-style)', function (): void {
    $fixture = seedIcsSummary(
        username: 'upsert-state',
        accountKind: 'ics_card',
        closingBalanceMinor: -10000,
        periodStart: '2026-03-01 00:00:00',
        periodEnd: '2026-03-31 23:59:59',
    );

    $this->upserter->upsertForImportRun($fixture['run']->id, $fixture['user']);

    /** @var CardStatement $row */
    $row = CardStatement::query()->where('user_id', $fixture['user']->id)->firstOrFail();
    $row->state = 'partially_settled';
    $row->save();

    $second = $this->upserter->upsertForImportRun($fixture['run']->id, $fixture['user']);
    expect($second)->toBe(0);
    expect(CardStatement::query()->where('user_id', $fixture['user']->id)->count())->toBe(1);

    /** @var CardStatement $after */
    $after = CardStatement::query()->where('user_id', $fixture['user']->id)->firstOrFail();
    expect($after->state)->toBe('partially_settled');
});

it('skips statement_summaries whose owning account.kind is not ics_card', function (): void {
    // statement_summaries has UNIQUE (user_id, import_run_id), so the ASN and
    // PayPal summaries live on their own import_runs and the filter is
    // verified by upserting against each run separately.
    $asnUser = User::query()->create([
        'username' => 'upsert-kind-asn',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    $asnAccount = Account::query()->create([
        'user_id' => $asnUser->id,
        'name' => 'Bank ASN',
        'slug' => 'kind-asn-bank',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);
    $asnRun = ImportRun::query()->create([
        'user_id' => $asnUser->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/kind-asn.csv',
        'sha256' => str_repeat('k', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);
    StatementSummary::query()->create([
        'user_id' => $asnUser->id,
        'import_run_id' => $asnRun->id,
        'account_id' => $asnAccount->id,
        'iban_owner' => $asnAccount->iban,
        'statement_number' => 'ASN-2026-02',
        'period_start' => '2026-02-01 00:00:00',
        'period_end' => '2026-02-28 23:59:59',
        'opening_balance_minor' => 100000,
        'opening_balance_currency' => 'EUR',
        'closing_balance_minor' => 80000,
        'closing_balance_currency' => 'EUR',
        'entry_count' => 12,
    ]);

    // ASN summary present, but accounts.kind='bank' fails the filter.
    $inserted = $this->upserter->upsertForImportRun($asnRun->id, $asnUser);

    expect($inserted)->toBe(0);
    expect(CardStatement::query()->where('user_id', $asnUser->id)->count())->toBe(0);

    // A PayPal summary also fails, proving the predicate is kind='ics_card'
    // and not kind!='bank'.
    $paypalAccount = Account::query()->create([
        'user_id' => $asnUser->id,
        'name' => 'PayPal',
        'slug' => 'kind-asn-paypal',
        'kind' => 'paypal',
        'iban' => 'PAYPAL',
        'default_currency' => 'EUR',
    ]);
    $paypalRun = ImportRun::query()->create([
        'user_id' => $asnUser->id,
        'source_format' => 'paypal-csv',
        'raw_file_path' => '/tmp/kind-pp.csv',
        'sha256' => str_repeat('p', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);
    StatementSummary::query()->create([
        'user_id' => $asnUser->id,
        'import_run_id' => $paypalRun->id,
        'account_id' => $paypalAccount->id,
        'iban_owner' => $paypalAccount->iban,
        'statement_number' => 'PP-2026-02',
        'period_start' => '2026-02-01 00:00:00',
        'period_end' => '2026-02-28 23:59:59',
        'opening_balance_minor' => 0,
        'opening_balance_currency' => 'EUR',
        'closing_balance_minor' => 0,
        'closing_balance_currency' => 'EUR',
        'entry_count' => 0,
    ]);

    $paypalInserted = $this->upserter->upsertForImportRun($paypalRun->id, $asnUser);
    expect($paypalInserted)->toBe(0);
    expect(CardStatement::query()->where('user_id', $asnUser->id)->count())->toBe(0);
});

it('skips statement_summaries with null period_start or null period_end', function (): void {
    // The UNIQUE (user_id, import_run_id) means one summary per run, so this
    // seeds a single run whose period_end is NULL.
    $fixture = seedIcsSummary(
        username: 'upsert-null',
        accountKind: 'ics_card',
        closingBalanceMinor: -9999,
        periodStart: '2026-01-01 00:00:00',
        periodEnd: null,
    );

    $inserted = $this->upserter->upsertForImportRun($fixture['run']->id, $fixture['user']);

    expect($inserted)->toBe(0);
    expect(CardStatement::query()->where('user_id', $fixture['user']->id)->count())->toBe(0);

    // The reverse case — period_start NULL but period_end set — is
    // covered by the same predicate.
    $startNullFixture = seedIcsSummary(
        username: 'upsert-null-start',
        accountKind: 'ics_card',
        closingBalanceMinor: -7777,
        periodStart: null,
        periodEnd: '2026-02-28 23:59:59',
    );
    $insertedStartNull = $this->upserter->upsertForImportRun(
        $startNullFixture['run']->id,
        $startNullFixture['user'],
    );
    expect($insertedStartNull)->toBe(0);
    expect(CardStatement::query()->where('user_id', $startNullFixture['user']->id)->count())->toBe(0);
});

it('is per-import-run scoped — does NOT promote rows from prior import runs', function (): void {
    $fixture = seedIcsSummary(
        username: 'upsert-scope',
        accountKind: 'ics_card',
        closingBalanceMinor: -10000,
        periodStart: '2026-01-01 00:00:00',
        periodEnd: '2026-01-31 23:59:59',
    );

    $secondRun = ImportRun::query()->create([
        'user_id' => $fixture['user']->id,
        'source_format' => 'ics-pdf',
        'raw_file_path' => '/tmp/second-run.pdf',
        'sha256' => str_repeat('2', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);
    StatementSummary::query()->create([
        'user_id' => $fixture['user']->id,
        'import_run_id' => $secondRun->id,
        'account_id' => $fixture['account']->id,
        'iban_owner' => $fixture['account']->iban,
        'statement_number' => 'STMT-2026-02',
        'period_start' => '2026-02-01 00:00:00',
        'period_end' => '2026-02-28 23:59:59',
        'opening_balance_minor' => 0,
        'opening_balance_currency' => 'EUR',
        'closing_balance_minor' => -20000,
        'closing_balance_currency' => 'EUR',
        'entry_count' => 1,
    ]);

    $inserted = $this->upserter->upsertForImportRun($secondRun->id, $fixture['user']);

    expect($inserted)->toBe(1);
    // The January summary stays unpromoted: its import_run_id is the first run.
    expect(CardStatement::query()->where('user_id', $fixture['user']->id)->count())->toBe(1);
    /** @var CardStatement $row */
    $row = CardStatement::query()->where('user_id', $fixture['user']->id)->firstOrFail();
    expect($row->import_run_id)->toBe($secondRun->id);
    expect($row->total_amount_minor)->toBe(-20000);
});
