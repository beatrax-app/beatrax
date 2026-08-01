<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Categorization\Internal\Services\RuleEvaluator;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

/*
 * Plan 13.4-06 rewrite (Rule 1 — direct consequence of this plan's own
 * demotion of RuleEvaluator to a memory-only fallback lookup, D-06).
 *
 * Every rule-matching/specificity-scoring test case that used to live
 * here was deleted: `RuleEngine` + `RuleApplier` now own ALL
 * `categorization_rules` matching/application (see
 * `RuleEngineConditionMatchingTest`/`RuleEngineOrderingTest`, Plan 02,
 * for that coverage). What remains is RuleEvaluator's sole surviving
 * responsibility — the `merchant_memories` fallback lookup, exercised
 * directly via `lookupMemory()` (the shape `ApplyAutoCategoryStage`
 * actually calls in production — the `evaluate()`/`RuleEvaluationOutcome`
 * wrapper this file used to also cover had no production caller and was
 * removed as dead code, IN-01).
 */

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'rules-test',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN',
        'slug' => 'asn',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);

    $this->streaming = Category::create([
        'user_id' => null,
        'name' => 'Streaming',
        'slug' => 'streaming',
        'kind' => 'expense',
        'display_order' => 100,
    ]);

    $this->groceries = Category::create([
        'user_id' => null,
        'name' => 'Groceries',
        'slug' => 'groceries',
        'kind' => 'expense',
        'display_order' => 200,
    ]);
});

function makeRuleEvalCanonical(int $userId, int $accountId, ?string $counterpartyName, string $counterpartyNormalized, ?string $description = null): CanonicalTransaction
{
    return new CanonicalTransaction(
        userId: $userId,
        accountId: $accountId,
        type: 'expense',
        postedAt: CarbonImmutable::parse('2026-05-03'),
        bookedAt: CarbonImmutable::parse('2026-05-03 12:00:00'),
        valueDate: CarbonImmutable::parse('2026-05-03'),
        amountMinor: -1299,
        currency: 'EUR',
        settledAmountMinor: -1299,
        settledCurrency: 'EUR',
        fxRateUsed: null,
        counterpartyName: $counterpartyName,
        counterpartyIban: null,
        counterpartyNormalized: $counterpartyNormalized,
        normalizationVersion: 1,
        description: $description,
        categoryId: null,
        sourceFormat: 'asn-csv',
        importRunId: 1,
        sourceRowIndex: 0,
        sourceRef: null,
    );
}

function seedMerchantAndMemory(int $userId, string $normalizedName, int $categoryId, int $occurrenceCount = 1): array
{
    $merchantId = (int) DB::table('merchants')->insertGetId([
        'user_id' => $userId,
        'name' => $normalizedName,
        'normalized_name' => $normalizedName,
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);

    $memoryId = (int) DB::table('merchant_memories')->insertGetId([
        'user_id' => $userId,
        'merchant_id' => $merchantId,
        'category_id' => $categoryId,
        'occurrence_count' => $occurrenceCount,
        'last_seen_at' => CarbonImmutable::now()->toDateTimeString(),
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);

    return ['merchant_id' => $merchantId, 'memory_id' => $memoryId];
}

it('returns no candidate when nothing matches', function (): void {
    $evaluator = $this->app->make(RuleEvaluator::class);
    $tx = makeRuleEvalCanonical($this->user->id, $this->account->id, 'Random Merchant', 'random merchant');

    $row = $evaluator->lookupMemory($tx, $this->user->id);

    expect($row)->toBeNull();
});

it('falls back to memory when a memory exists', function (): void {
    $seeded = seedMerchantAndMemory($this->user->id, 'spotify premium', $this->streaming->id, occurrenceCount: 4);

    $evaluator = $this->app->make(RuleEvaluator::class);
    $tx = makeRuleEvalCanonical($this->user->id, $this->account->id, 'Spotify Premium', 'spotify premium');

    $row = $evaluator->lookupMemory($tx, $this->user->id);

    expect($row)->not->toBeNull();
    expect((int) $row->category_id)->toBe($this->streaming->id);
    expect((int) $row->id)->toBe($seeded['memory_id']);
});

it('does NOT fire a foreign-user memory for the current user', function (): void {
    $other = User::create([
        'username' => 'other-memory',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);

    seedMerchantAndMemory($other->id, 'spotify premium', $this->groceries->id);

    $evaluator = $this->app->make(RuleEvaluator::class);
    $tx = makeRuleEvalCanonical($this->user->id, $this->account->id, 'Spotify Premium', 'spotify premium');

    $row = $evaluator->lookupMemory($tx, $this->user->id);

    expect($row)->toBeNull();
});

it('skips the memory lookup when counterparty_normalized is the empty sentinel', function (): void {
    seedMerchantAndMemory($this->user->id, '_no_counterparty', $this->streaming->id);

    $evaluator = $this->app->make(RuleEvaluator::class);
    $tx = makeRuleEvalCanonical($this->user->id, $this->account->id, null, '_no_counterparty');

    $row = $evaluator->lookupMemory($tx, $this->user->id);

    expect($row)->toBeNull();
});

it('memory uses highest occurrence_count when multiple memories exist for the same merchant', function (): void {
    // Two memories for the same merchant, different categories: groceries=10, streaming=2 → groceries wins
    $merchantId = (int) DB::table('merchants')->insertGetId([
        'user_id' => $this->user->id,
        'name' => 'spotify premium',
        'normalized_name' => 'spotify premium',
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);

    DB::table('merchant_memories')->insert([
        'user_id' => $this->user->id,
        'merchant_id' => $merchantId,
        'category_id' => $this->streaming->id,
        'occurrence_count' => 2,
        'last_seen_at' => CarbonImmutable::now()->toDateTimeString(),
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);
    DB::table('merchant_memories')->insert([
        'user_id' => $this->user->id,
        'merchant_id' => $merchantId,
        'category_id' => $this->groceries->id,
        'occurrence_count' => 10,
        'last_seen_at' => CarbonImmutable::now()->toDateTimeString(),
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);

    $evaluator = $this->app->make(RuleEvaluator::class);
    $tx = makeRuleEvalCanonical($this->user->id, $this->account->id, 'Spotify Premium', 'spotify premium');

    $row = $evaluator->lookupMemory($tx, $this->user->id);

    expect($row)->not->toBeNull();
    expect((int) $row->category_id)->toBe($this->groceries->id);
});

it('lookupMemory() is public and returns the raw memory row directly', function (): void {
    $seeded = seedMerchantAndMemory($this->user->id, 'spotify premium', $this->streaming->id, occurrenceCount: 4);

    $evaluator = $this->app->make(RuleEvaluator::class);
    $tx = makeRuleEvalCanonical($this->user->id, $this->account->id, 'Spotify Premium', 'spotify premium');

    $row = $evaluator->lookupMemory($tx, $this->user->id);

    expect($row)->not->toBeNull();
    expect((int) $row->id)->toBe($seeded['memory_id']);
    expect((int) $row->category_id)->toBe($this->streaming->id);
});
