<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use Modules\Categorization\Internal\Jobs\ReapplyRulesJob;
use Modules\Categorization\Internal\Services\RuleApplier;
use Modules\Categorization\Internal\Services\RuleEngine;
use Modules\Categorization\Models\CategorizationRule;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Counterparties\Models\Counterparty;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Actions\SaveTransactionSplit;
use Modules\Ledger\Public\Services\FieldProvenanceWriter;
use Modules\Ledger\Public\Services\TransactionStatusQuery;
use Psr\Log\LoggerInterface;

/*
 * 13.4-07 Task 2 (Req 4): ReapplyRulesJob idempotency + guards.
 *
 * Proves the user-triggered re-apply-to-history path end-to-end:
 *  (a) a manually-edited field survives re-apply while a rule-owned,
 *      untouched field on the same row still gets set (D-04);
 *  (b) a reconciled transaction is left completely untouched (T-13.4-22);
 *  (c) a split transaction's legs are never touched (T-13.4-23);
 *  (d) a second identical pass writes zero fields (idempotency, Req 4);
 *  (e) all four action types apply together to a plain eligible row.
 */

function reapplyRunJob(int $userId): void
{
    /** @var ReapplyRulesJob $job */
    $job = app(ReapplyRulesJob::class, ['userId' => $userId]);
    $job->handle(
        app(RuleEngine::class),
        app(RuleApplier::class),
        app(TransactionStatusQuery::class),
        app(DatabaseManager::class),
        app(CacheRepository::class),
        app(Clock::class),
        app(LoggerInterface::class),
    );
}

/**
 * @return array{0: mixed, ...}
 */
function reapplyProgress(int $userId): array
{
    /** @var CacheRepository $cache */
    $cache = app(CacheRepository::class);
    $raw = $cache->get(ReapplyRulesJob::progressCacheKey($userId));

    return is_array($raw) ? $raw : [];
}

/**
 * @return array{user: User, account: Account, run: ImportRun, ruleCategory: Category, manualCategory: Category, counterparty: Counterparty, deductionCategoryId: int}
 */
function seedReapplyFixtures(): array
{
    $user = User::query()->create([
        'username' => 'reapply-job-test-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    $account = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'ASN reapply-job',
        'slug' => 'reapply-job-asn-'.bin2hex(random_bytes(4)),
        'kind' => 'asn',
        'iban' => 'NL00ASNB'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
    ]);

    $run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/reapply-job.xml',
        'sha256' => hash('sha256', 'reapply-job-'.bin2hex(random_bytes(8))),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    $ruleCategory = Category::query()->create([
        'user_id' => null,
        'name' => 'Streaming reapply',
        'slug' => 'reapply-job-streaming-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'display_order' => 1,
    ]);

    $manualCategory = Category::query()->create([
        'user_id' => null,
        'name' => 'Manual reapply',
        'slug' => 'reapply-job-manual-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'display_order' => 2,
    ]);

    $counterparty = Counterparty::query()->create([
        'user_id' => $user->id,
        'type' => 'merchant',
        'slug' => 'reapply-job-spotify-'.bin2hex(random_bytes(3)),
        'display_name' => 'Spotify AB',
    ]);

    $deductionCategoryId = (int) app(DatabaseManager::class)->connection()->table('tax_deduction_categories')->insertGetId([
        'user_id' => $user->id,
        'name' => 'Reapply subs',
        'short_name' => 'RPS',
        'status' => 'active',
        'sort_order' => 0,
        'created_at' => CarbonImmutable::now(),
        'updated_at' => CarbonImmutable::now(),
    ]);

    return [
        'user' => $user,
        'account' => $account,
        'run' => $run,
        'ruleCategory' => $ruleCategory,
        'manualCategory' => $manualCategory,
        'counterparty' => $counterparty,
        'deductionCategoryId' => $deductionCategoryId,
    ];
}

/**
 * A single rule that matches counterparty "Spotify AB" and carries all
 * four action types (category/counterparty/note/tax_tag).
 */
function makeReapplyRule(int $userId, int $categoryId, int $counterpartyId, int $deductionCategoryId): CategorizationRule
{
    $rule = CategorizationRule::query()->create([
        'user_id' => $userId,
        'priority' => 0,
        'active' => true,
        'combinator' => 'all',
        'notes' => null,
        'hits_count' => 0,
    ]);

    $rule->conditions()->create([
        'field' => 'counterparty',
        'op' => 'equals',
        'value_type' => 'string',
        'value' => 'Spotify AB',
        'value2' => null,
    ]);

    $rule->actions()->create(['position' => 0, 'type' => 'category', 'payload' => ['category_id' => $categoryId]]);
    $rule->actions()->create(['position' => 1, 'type' => 'counterparty', 'payload' => ['counterparty_id' => $counterpartyId]]);
    $rule->actions()->create(['position' => 2, 'type' => 'note', 'payload' => ['text' => 'Rule note', 'mode' => 'set']]);
    $rule->actions()->create(['position' => 3, 'type' => 'tax_tag', 'payload' => ['deduction_category_id' => $deductionCategoryId]]);

    return $rule;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function makeReapplyTx(User $user, Account $account, ImportRun $run, int $rowIndex, array $overrides = []): Transaction
{
    return Transaction::query()->create(array_merge([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => '2026-07-05',
        'booked_at' => '2026-07-05 12:00:00',
        'value_date' => '2026-07-05',
        'amount_minor' => -1000,
        'currency' => 'EUR',
        'settled_amount_minor' => -1000,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Spotify AB',
        'counterparty_normalized' => 'spotify ab',
        'normalization_version' => 1,
        'source_format' => 'camt053',
        'import_run_id' => $run->id,
        'source_row_index' => $rowIndex,
        'fingerprint' => str_pad('reapply-job-'.$rowIndex, 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ], $overrides));
}

it('preserves a manually-edited category while still setting a rule-owned untouched field', function (): void {
    $fixtures = seedReapplyFixtures();
    makeReapplyRule($fixtures['user']->id, $fixtures['ruleCategory']->id, $fixtures['counterparty']->id, $fixtures['deductionCategoryId']);

    $tx = makeReapplyTx($fixtures['user'], $fixtures['account'], $fixtures['run'], 1, [
        'category_id' => $fixtures['manualCategory']->id,
    ]);
    app(FieldProvenanceWriter::class)->stamp($fixtures['user']->id, $tx->id, ['category_id' => 'manual']);

    reapplyRunJob($fixtures['user']->id);

    $tx->refresh();
    expect($tx->category_id)->toBe($fixtures['manualCategory']->id);
    expect($tx->note)->toBe('Rule note');
});

it('leaves a reconciled transaction completely untouched', function (): void {
    $fixtures = seedReapplyFixtures();
    makeReapplyRule($fixtures['user']->id, $fixtures['ruleCategory']->id, $fixtures['counterparty']->id, $fixtures['deductionCategoryId']);

    $tx = makeReapplyTx($fixtures['user'], $fixtures['account'], $fixtures['run'], 2, [
        'status' => 'reconciled',
    ]);

    reapplyRunJob($fixtures['user']->id);

    $tx->refresh();
    expect($tx->category_id)->toBeNull();
    expect($tx->counterparty_id)->toBeNull();
    expect($tx->note)->toBeNull();
    expect(DB::table('tax_transaction_tags')->where('transaction_id', $tx->id)->exists())->toBeFalse();

    $progress = reapplyProgress($fixtures['user']->id);
    expect((int) $progress['reconciled_skipped'])->toBeGreaterThanOrEqual(1);
});

it('never touches a split transaction or its legs', function (): void {
    $fixtures = seedReapplyFixtures();
    makeReapplyRule($fixtures['user']->id, $fixtures['ruleCategory']->id, $fixtures['counterparty']->id, $fixtures['deductionCategoryId']);

    $tx = makeReapplyTx($fixtures['user'], $fixtures['account'], $fixtures['run'], 3);

    app(SaveTransactionSplit::class)->save($fixtures['user'], $tx->id, [
        ['id' => null, 'category_id' => $fixtures['manualCategory']->id, 'settled_amount_minor' => -600, 'note' => null],
        ['id' => null, 'category_id' => $fixtures['ruleCategory']->id, 'settled_amount_minor' => -400, 'note' => null],
    ]);

    $legsBefore = DB::table('transaction_splits')->where('transaction_id', $tx->id)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    expect($legsBefore)->toHaveCount(2);

    reapplyRunJob($fixtures['user']->id);

    $legsAfter = DB::table('transaction_splits')->where('transaction_id', $tx->id)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    expect($legsAfter)->toBe($legsBefore);

    $tx->refresh();
    expect($tx->category_id)->toBeNull();
    expect($tx->counterparty_id)->toBeNull();
    expect($tx->note)->toBeNull();
});

it('applies all four action types to a plain eligible transaction', function (): void {
    $fixtures = seedReapplyFixtures();
    makeReapplyRule($fixtures['user']->id, $fixtures['ruleCategory']->id, $fixtures['counterparty']->id, $fixtures['deductionCategoryId']);

    $tx = makeReapplyTx($fixtures['user'], $fixtures['account'], $fixtures['run'], 4);

    reapplyRunJob($fixtures['user']->id);

    $tx->refresh();
    expect($tx->category_id)->toBe($fixtures['ruleCategory']->id);
    expect($tx->counterparty_id)->toBe($fixtures['counterparty']->id);
    expect($tx->note)->toBe('Rule note');

    $tagRow = DB::table('tax_transaction_tags')->where('transaction_id', $tx->id)->first();
    expect($tagRow)->not->toBeNull();
    expect((int) $tagRow->deduction_category_id)->toBe($fixtures['deductionCategoryId']);
});

it('a second identical re-apply pass writes zero fields (idempotent)', function (): void {
    $fixtures = seedReapplyFixtures();
    makeReapplyRule($fixtures['user']->id, $fixtures['ruleCategory']->id, $fixtures['counterparty']->id, $fixtures['deductionCategoryId']);

    makeReapplyTx($fixtures['user'], $fixtures['account'], $fixtures['run'], 5);

    reapplyRunJob($fixtures['user']->id);
    $firstPassProgress = reapplyProgress($fixtures['user']->id);
    expect((int) $firstPassProgress['fields_updated'])->toBeGreaterThan(0);
    expect($firstPassProgress['status'])->toBe('done');

    reapplyRunJob($fixtures['user']->id);
    $secondPassProgress = reapplyProgress($fixtures['user']->id);

    expect((int) $secondPassProgress['fields_updated'])->toBe(0);
    expect((int) $secondPassProgress['transactions_updated'])->toBe(0);
    expect($secondPassProgress['status'])->toBe('done');
});

it('completes the job instead of throwing when a rule\'s malformed date condition value crashes matching (WR-03)', function (): void {
    $fixtures = seedReapplyFixtures();

    // A date-type condition whose stored `value` is not a well-formed
    // date string — neither CreateCategorizationRule's nor
    // UpdateCategorizationRule's normalizeCondition() validates the
    // date FORMAT of a date value_type (only non-empty), so this is
    // reachable via any non-`<input type=date>` caller, or residual bad
    // data. RuleEngine::matchDate() calls CarbonImmutable::parse() on
    // it, which throws — and because RuleEngine::match() evaluates
    // every active rule against every row with no per-condition
    // short-circuit, this ONE broken rule poisons match() for every
    // transaction in the walk, not just one row. Before the fix, that
    // exception propagated uncaught out of the chunk closure, failing
    // the whole queued job (tries exhausted, progress stuck at
    // 'running' forever). After the fix, each row is caught and
    // skipped individually and the job still reaches 'done'.
    $brokenRule = CategorizationRule::query()->create([
        'user_id' => $fixtures['user']->id,
        'priority' => 0,
        'active' => true,
        'combinator' => 'all',
        'notes' => null,
        'hits_count' => 0,
    ]);
    $brokenRule->conditions()->create([
        'field' => 'merchant',
        'op' => 'after',
        'value_type' => 'date',
        'value' => 'not-a-real-date',
        'value2' => null,
    ]);
    $brokenRule->actions()->create(['position' => 0, 'type' => 'category', 'payload' => ['category_id' => $fixtures['ruleCategory']->id]]);

    $tx = makeReapplyTx($fixtures['user'], $fixtures['account'], $fixtures['run'], 6);

    // The call itself must not throw — pre-fix, this line would raise
    // an uncaught Carbon\Exceptions\InvalidFormatException, failing this
    // test (and, in production, the whole queued job).
    reapplyRunJob($fixtures['user']->id);

    $progress = reapplyProgress($fixtures['user']->id);
    expect($progress['status'])->toBe('done');
    expect((int) $progress['rows_errored'])->toBeGreaterThanOrEqual(1);

    // The row was skipped (fail-open), not silently mis-categorized.
    $tx->refresh();
    expect($tx->category_id)->toBeNull();
});
