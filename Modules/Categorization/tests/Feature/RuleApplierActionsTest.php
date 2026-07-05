<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Categorization\Internal\Services\MatchedRule;
use Modules\Categorization\Internal\Services\RuleApplier;
use Modules\Categorization\Models\RuleAction;
use Modules\Core\Models\User;
use Modules\Counterparties\Models\Counterparty;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Ledger\Public\Services\FieldProvenanceWriter;
use Modules\Sync\Public\Events\TransactionMutated;

/*
 * Plan 13.4-05 — RuleApplier dual-mode action executor (D-06, Req 2/3).
 *
 * Task 1: import-mode folding — category/counterparty/note withers,
 * tax_tag import-deferred (Pitfall 4), last-writer-wins across
 * same-field actions, zero side effects (no DB write, no event
 * dispatch).
 *
 * Task 2 (below): re-apply mode — provenance guard (manual-preserving),
 * write-only-on-change idempotency, TransactionMutated sync dispatch,
 * fail-open on a dangling payload id.
 */

function baseCanonicalForRuleApplier(): CanonicalTransaction
{
    return new CanonicalTransaction(
        userId: 1,
        accountId: 1,
        type: 'expense',
        postedAt: CarbonImmutable::parse('2026-07-01'),
        bookedAt: CarbonImmutable::parse('2026-07-01 12:00:00'),
        valueDate: CarbonImmutable::parse('2026-07-01'),
        amountMinor: -1000,
        currency: 'EUR',
        settledAmountMinor: -1000,
        settledCurrency: 'EUR',
        fxRateUsed: null,
        counterpartyName: 'Spotify AB',
        counterpartyIban: null,
        counterpartyNormalized: 'spotify ab',
        normalizationVersion: 1,
        description: 'Music subscription',
        categoryId: null,
        sourceFormat: 'camt053',
        importRunId: 1,
        sourceRowIndex: 1,
        sourceRef: null,
    );
}

/**
 * Builds an unpersisted `RuleAction` model — import-mode folding never
 * touches the DB, so a `MatchedRule`'s `actions` list can be built
 * directly against the fillable attributes without an `insert()`.
 *
 * @param  array<string, mixed>  $payload
 */
function ruleActionFor(string $type, array $payload, int $position = 0): RuleAction
{
    return new RuleAction([
        'position' => $position,
        'type' => $type,
        'payload' => $payload,
    ]);
}

function ruleApplierForTest(): RuleApplier
{
    return app(RuleApplier::class);
}

/**
 * @return array{user: User, account: Account, run: ImportRun}
 */
function seedRuleApplierFixtures(): array
{
    $user = User::query()->create([
        'username' => 'rule-applier-test-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    $account = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'ASN rule-applier',
        'slug' => 'rule-applier-asn-'.bin2hex(random_bytes(4)),
        'kind' => 'asn',
        'iban' => 'NL00ASNB'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
    ]);

    $run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/rule-applier.xml',
        'sha256' => hash('sha256', 'rule-applier-'.bin2hex(random_bytes(8))),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    return ['user' => $user, 'account' => $account, 'run' => $run];
}

function makeRuleApplierTransaction(User $user, Account $account, ImportRun $run, int $rowIndex, array $overrides = []): Transaction
{
    return Transaction::query()->create(array_merge([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => '2026-07-01',
        'booked_at' => '2026-07-01 12:00:00',
        'value_date' => '2026-07-01',
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
        'fingerprint' => str_pad('rule-applier-'.$rowIndex, 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ], $overrides));
}

function makeRuleApplierCategory(string $name): Category
{
    return Category::query()->create([
        'user_id' => null,
        'name' => $name,
        'slug' => strtolower($name).'-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'display_order' => 1,
    ]);
}

function makeRuleApplierCounterparty(User $user, string $name): Counterparty
{
    return Counterparty::query()->create([
        'user_id' => $user->id,
        'type' => 'merchant',
        'slug' => strtolower($name).'-'.bin2hex(random_bytes(3)),
        'display_name' => $name,
    ]);
}

function makeRuleApplierDeductionCategory(DatabaseManager $db, User $user, string $name): int
{
    return (int) $db->connection()->table('tax_deduction_categories')->insertGetId([
        'user_id' => $user->id,
        'name' => $name,
        'short_name' => substr($name, 0, 3),
        'status' => 'active',
        'sort_order' => 0,
        'created_at' => CarbonImmutable::now(),
        'updated_at' => CarbonImmutable::now(),
    ]);
}

// --- Task 1: import mode ---

it('folds category + counterparty + note onto the DTO at import; tax_tag is ignored', function (): void {
    $matched = [
        new MatchedRule(ruleId: 1, priority: 0, actions: [
            ruleActionFor('category', ['category_id' => 42], 0),
            ruleActionFor('counterparty', ['counterparty_id' => 7], 1),
            ruleActionFor('note', ['text' => 'Rule note', 'mode' => 'set'], 2),
            ruleActionFor('tax_tag', ['deduction_category_id' => 5], 3),
        ]),
    ];

    $result = ruleApplierForTest()->applyAtImport($matched, baseCanonicalForRuleApplier());

    expect($result->categoryId)->toBe(42);
    expect($result->counterpartyId)->toBe(7);
    expect($result->note)->toBe('Rule note');
});

it('resolves last-writer-wins on the DTO when two matching rules both set category', function (): void {
    $matched = [
        new MatchedRule(ruleId: 1, priority: 0, actions: [
            ruleActionFor('category', ['category_id' => 1], 0),
        ]),
        new MatchedRule(ruleId: 2, priority: 1, actions: [
            ruleActionFor('category', ['category_id' => 2], 0),
        ]),
    ];

    $result = ruleApplierForTest()->applyAtImport($matched, baseCanonicalForRuleApplier());

    expect($result->categoryId)->toBe(2);
});

it('skips a malformed category action (missing category_id) without throwing', function (): void {
    $matched = [
        new MatchedRule(ruleId: 1, priority: 0, actions: [
            ruleActionFor('category', [], 0),
        ]),
    ];

    $result = ruleApplierForTest()->applyAtImport($matched, baseCanonicalForRuleApplier());

    expect($result->categoryId)->toBeNull();
});

// --- Task 2: re-apply mode ---

it('applies all four actions to a persisted transaction', function (): void {
    Event::fake([TransactionMutated::class]);

    ['user' => $user, 'account' => $account, 'run' => $run] = seedRuleApplierFixtures();
    $tx = makeRuleApplierTransaction($user, $account, $run, 1);
    $category = makeRuleApplierCategory('Streaming');
    $counterparty = makeRuleApplierCounterparty($user, 'Spotify AB');
    $deductionCategoryId = makeRuleApplierDeductionCategory(app(DatabaseManager::class), $user, 'Subscriptions');

    $matched = [
        new MatchedRule(ruleId: 1, priority: 0, actions: [
            ruleActionFor('category', ['category_id' => $category->id], 0),
            ruleActionFor('counterparty', ['counterparty_id' => $counterparty->id], 1),
            ruleActionFor('note', ['text' => 'Rule note', 'mode' => 'set'], 2),
            ruleActionFor('tax_tag', ['deduction_category_id' => $deductionCategoryId], 3),
        ]),
    ];

    $changed = ruleApplierForTest()->applyAtReapply($matched, $tx->id, $user->id);

    expect($changed['category_id'])->toBe($category->id);
    expect($changed['counterparty_id'])->toBe($counterparty->id);
    expect($changed['note'])->toBe('Rule note');
    expect($changed['tax_tag'])->toBe($deductionCategoryId);

    $tx->refresh();
    expect($tx->category_id)->toBe($category->id);
    expect($tx->counterparty_id)->toBe($counterparty->id);
    expect($tx->note)->toBe('Rule note');

    $tagRow = DB::table('tax_transaction_tags')->where('transaction_id', $tx->id)->first();
    expect((int) $tagRow->deduction_category_id)->toBe($deductionCategoryId);

    // category + counterparty + note each dispatch their own
    // TransactionMutated — tax_tag delegates to TagTransaction, which
    // dispatches its own TransactionTagged event instead (no double-report).
    Event::assertDispatched(TransactionMutated::class, 3);
});

it('preserves a manual-provenance field and only writes the rule-owned field', function (): void {
    Event::fake([TransactionMutated::class]);

    ['user' => $user, 'account' => $account, 'run' => $run] = seedRuleApplierFixtures();
    $manualCategory = makeRuleApplierCategory('ManualCat');
    $ruleCategory = makeRuleApplierCategory('RuleCat');
    $counterparty = makeRuleApplierCounterparty($user, 'Netflix');

    $tx = makeRuleApplierTransaction($user, $account, $run, 2, ['category_id' => $manualCategory->id]);
    app(FieldProvenanceWriter::class)->stamp($user->id, $tx->id, ['category_id' => 'manual']);

    $matched = [
        new MatchedRule(ruleId: 1, priority: 0, actions: [
            ruleActionFor('category', ['category_id' => $ruleCategory->id], 0),
            ruleActionFor('counterparty', ['counterparty_id' => $counterparty->id], 1),
        ]),
    ];

    $changed = ruleApplierForTest()->applyAtReapply($matched, $tx->id, $user->id);

    expect($changed)->not->toHaveKey('category_id');
    expect($changed['counterparty_id'])->toBe($counterparty->id);

    $tx->refresh();
    expect($tx->category_id)->toBe($manualCategory->id);
    expect($tx->counterparty_id)->toBe($counterparty->id);
});

it('a second identical applyAtReapply is a no-op (idempotent, zero writes)', function (): void {
    Event::fake([TransactionMutated::class]);

    ['user' => $user, 'account' => $account, 'run' => $run] = seedRuleApplierFixtures();
    $tx = makeRuleApplierTransaction($user, $account, $run, 3);
    $category = makeRuleApplierCategory('Groceries');
    $counterparty = makeRuleApplierCounterparty($user, 'Albert Heijn');
    $deductionCategoryId = makeRuleApplierDeductionCategory(app(DatabaseManager::class), $user, 'Food');

    $matched = [
        new MatchedRule(ruleId: 1, priority: 0, actions: [
            ruleActionFor('category', ['category_id' => $category->id], 0),
            ruleActionFor('counterparty', ['counterparty_id' => $counterparty->id], 1),
            ruleActionFor('note', ['text' => 'Weekly shop', 'mode' => 'set'], 2),
            ruleActionFor('tax_tag', ['deduction_category_id' => $deductionCategoryId], 3),
        ]),
    ];

    $applier = ruleApplierForTest();
    $first = $applier->applyAtReapply($matched, $tx->id, $user->id);
    expect($first)->not->toBe([]);

    $second = $applier->applyAtReapply($matched, $tx->id, $user->id);
    expect($second)->toBe([]);
});

it('resolves last-writer-wins on a persisted field with exactly one TransactionMutated', function (): void {
    Event::fake([TransactionMutated::class]);

    ['user' => $user, 'account' => $account, 'run' => $run] = seedRuleApplierFixtures();
    $tx = makeRuleApplierTransaction($user, $account, $run, 4);
    $firstCategory = makeRuleApplierCategory('First');
    $secondCategory = makeRuleApplierCategory('Second');

    $matched = [
        new MatchedRule(ruleId: 1, priority: 0, actions: [
            ruleActionFor('category', ['category_id' => $firstCategory->id], 0),
        ]),
        new MatchedRule(ruleId: 2, priority: 1, actions: [
            ruleActionFor('category', ['category_id' => $secondCategory->id], 0),
        ]),
    ];

    $changed = ruleApplierForTest()->applyAtReapply($matched, $tx->id, $user->id);

    expect($changed['category_id'])->toBe($secondCategory->id);

    $tx->refresh();
    expect($tx->category_id)->toBe($secondCategory->id);

    Event::assertDispatched(TransactionMutated::class, 1);
});

it('skips a dangling category id but still applies the other action', function (): void {
    Event::fake([TransactionMutated::class]);

    ['user' => $user, 'account' => $account, 'run' => $run] = seedRuleApplierFixtures();
    $tx = makeRuleApplierTransaction($user, $account, $run, 5);
    $counterparty = makeRuleApplierCounterparty($user, 'Netflix');

    $matched = [
        new MatchedRule(ruleId: 1, priority: 0, actions: [
            ruleActionFor('category', ['category_id' => 999999], 0),
            ruleActionFor('counterparty', ['counterparty_id' => $counterparty->id], 1),
        ]),
    ];

    $changed = ruleApplierForTest()->applyAtReapply($matched, $tx->id, $user->id);

    expect($changed)->not->toHaveKey('category_id');
    expect($changed['counterparty_id'])->toBe($counterparty->id);

    $tx->refresh();
    expect($tx->category_id)->toBeNull();
    expect($tx->counterparty_id)->toBe($counterparty->id);
});

it('skips a tax_tag action when field_provenance.tax_tag is manual', function (): void {
    Event::fake([TransactionMutated::class]);

    ['user' => $user, 'account' => $account, 'run' => $run] = seedRuleApplierFixtures();
    $tx = makeRuleApplierTransaction($user, $account, $run, 6);
    $deductionCategoryId = makeRuleApplierDeductionCategory(app(DatabaseManager::class), $user, 'Manual tag');

    app(FieldProvenanceWriter::class)->stamp($user->id, $tx->id, ['tax_tag' => 'manual']);

    $matched = [
        new MatchedRule(ruleId: 1, priority: 0, actions: [
            ruleActionFor('tax_tag', ['deduction_category_id' => $deductionCategoryId], 0),
        ]),
    ];

    $changed = ruleApplierForTest()->applyAtReapply($matched, $tx->id, $user->id);

    expect($changed)->toBe([]);
    expect(DB::table('tax_transaction_tags')->where('transaction_id', $tx->id)->exists())->toBeFalse();
});
