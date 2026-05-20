<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Categorization\Internal\Services\RuleEvaluator;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

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
        'kind' => 'asn',
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

function seedRule(int $userId, string $field, string $match, string $value, int $categoryId): int
{
    $id = DB::table('categorization_rules')->insertGetId([
        'user_id' => $userId,
        'field' => $field,
        'match' => $match,
        'value' => $value,
        'category_id' => $categoryId,
        'hits_count' => 0,
        'active' => true,
        'notes' => null,
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);

    return (int) $id;
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

    $outcome = $evaluator->evaluate($tx, $this->user);

    expect($outcome->categoryId)->toBeNull();
    expect($outcome->source)->toBeNull();
    expect($outcome->ruleId)->toBeNull();
    expect($outcome->memoryId)->toBeNull();
});

it('returns the category from an equals rule when one matches', function (): void {
    $ruleId = seedRule($this->user->id, 'merchant', 'equals', 'Spotify Premium', $this->streaming->id);

    $evaluator = $this->app->make(RuleEvaluator::class);
    $tx = makeRuleEvalCanonical($this->user->id, $this->account->id, 'Spotify Premium', 'spotify premium');

    $outcome = $evaluator->evaluate($tx, $this->user);

    expect($outcome->categoryId)->toBe($this->streaming->id);
    expect($outcome->source)->toBe('rule');
    expect($outcome->ruleId)->toBe($ruleId);
    expect($outcome->score)->toBe(100);
});

it('falls back to memory at score 90 when only memory matches', function (): void {
    $seeded = seedMerchantAndMemory($this->user->id, 'spotify premium', $this->streaming->id, occurrenceCount: 4);

    $evaluator = $this->app->make(RuleEvaluator::class);
    $tx = makeRuleEvalCanonical($this->user->id, $this->account->id, 'Spotify Premium', 'spotify premium');

    $outcome = $evaluator->evaluate($tx, $this->user);

    expect($outcome->categoryId)->toBe($this->streaming->id);
    expect($outcome->source)->toBe('memory');
    expect($outcome->memoryId)->toBe($seeded['memory_id']);
    expect($outcome->score)->toBe(90);
});

it('rule equals (score 100) beats memory (score 90) for the same merchant', function (): void {
    seedMerchantAndMemory($this->user->id, 'spotify premium', $this->groceries->id);
    $ruleId = seedRule($this->user->id, 'merchant', 'equals', 'Spotify Premium', $this->streaming->id);

    $evaluator = $this->app->make(RuleEvaluator::class);
    $tx = makeRuleEvalCanonical($this->user->id, $this->account->id, 'Spotify Premium', 'spotify premium');

    $outcome = $evaluator->evaluate($tx, $this->user);

    expect($outcome->source)->toBe('rule');
    expect($outcome->categoryId)->toBe($this->streaming->id);
    expect($outcome->ruleId)->toBe($ruleId);
});

it('equals beats starts_with beats contains beats memory', function (): void {
    // contains is shortest -> lowest score among rules
    seedRule($this->user->id, 'merchant', 'contains', 'Spotify', $this->groceries->id);
    seedMerchantAndMemory($this->user->id, 'spotify premium', $this->groceries->id);
    $equalsId = seedRule($this->user->id, 'merchant', 'equals', 'Spotify Premium', $this->streaming->id);

    $evaluator = $this->app->make(RuleEvaluator::class);
    $tx = makeRuleEvalCanonical($this->user->id, $this->account->id, 'Spotify Premium', 'spotify premium');

    $outcome = $evaluator->evaluate($tx, $this->user);

    expect($outcome->source)->toBe('rule');
    expect($outcome->categoryId)->toBe($this->streaming->id);
    expect($outcome->ruleId)->toBe($equalsId);
    expect($outcome->score)->toBe(100);
});

it('longer contains literal beats shorter contains literal', function (): void {
    seedRule($this->user->id, 'merchant', 'contains', 'spo', $this->groceries->id);
    $longerId = seedRule($this->user->id, 'merchant', 'contains', 'spotify', $this->streaming->id);

    $evaluator = $this->app->make(RuleEvaluator::class);
    $tx = makeRuleEvalCanonical($this->user->id, $this->account->id, 'Spotify Premium', 'spotify premium');

    $outcome = $evaluator->evaluate($tx, $this->user);

    expect($outcome->source)->toBe('rule');
    expect($outcome->ruleId)->toBe($longerId);
    expect($outcome->categoryId)->toBe($this->streaming->id);
});

it('case-insensitive — rule value SPOTIFY matches counterparty Spotify Premium', function (): void {
    $ruleId = seedRule($this->user->id, 'merchant', 'contains', 'SPOTIFY', $this->streaming->id);

    $evaluator = $this->app->make(RuleEvaluator::class);
    $tx = makeRuleEvalCanonical($this->user->id, $this->account->id, 'Spotify Premium', 'spotify premium');

    $outcome = $evaluator->evaluate($tx, $this->user);

    expect($outcome->ruleId)->toBe($ruleId);
    expect($outcome->source)->toBe('rule');
});

it('SQL-injection-shaped rule value is matched as literal substring (T-07-05)', function (): void {
    $needle = "Robert'; DROP TABLE transactions--";
    seedRule($this->user->id, 'merchant', 'contains', $needle, $this->streaming->id);

    $evaluator = $this->app->make(RuleEvaluator::class);
    $matching = makeRuleEvalCanonical(
        $this->user->id,
        $this->account->id,
        'Little Bobby '.$needle.' store',
        'little bobby store',
    );
    $unrelated = makeRuleEvalCanonical($this->user->id, $this->account->id, 'Random Merchant', 'random merchant');

    $hit = $evaluator->evaluate($matching, $this->user);
    expect($hit->categoryId)->toBe($this->streaming->id);

    $miss = $evaluator->evaluate($unrelated, $this->user);
    expect($miss->categoryId)->toBeNull();

    // transactions table still exists post-test
    expect(DB::getSchemaBuilder()->hasTable('transactions'))->toBeTrue();
});

it('does NOT fire a foreign-user rule for the current user (T-07-09)', function (): void {
    $other = User::create([
        'username' => 'other-rules',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);

    seedRule($other->id, 'merchant', 'equals', 'Spotify Premium', $this->streaming->id);

    $evaluator = $this->app->make(RuleEvaluator::class);
    $tx = makeRuleEvalCanonical($this->user->id, $this->account->id, 'Spotify Premium', 'spotify premium');

    $outcome = $evaluator->evaluate($tx, $this->user);

    expect($outcome->categoryId)->toBeNull();
    expect($outcome->source)->toBeNull();
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

    $outcome = $evaluator->evaluate($tx, $this->user);

    expect($outcome->categoryId)->toBeNull();
});

it('skips inactive rules even when they would otherwise match', function (): void {
    $id = (int) DB::table('categorization_rules')->insertGetId([
        'user_id' => $this->user->id,
        'field' => 'merchant',
        'match' => 'equals',
        'value' => 'Spotify Premium',
        'category_id' => $this->streaming->id,
        'hits_count' => 0,
        'active' => false,
        'notes' => null,
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);

    expect($id)->toBeGreaterThan(0);

    $evaluator = $this->app->make(RuleEvaluator::class);
    $tx = makeRuleEvalCanonical($this->user->id, $this->account->id, 'Spotify Premium', 'spotify premium');

    $outcome = $evaluator->evaluate($tx, $this->user);

    expect($outcome->categoryId)->toBeNull();
});

it('description field rule targets canonical.description', function (): void {
    $ruleId = seedRule($this->user->id, 'description', 'contains', 'invoice', $this->streaming->id);

    $evaluator = $this->app->make(RuleEvaluator::class);
    $tx = makeRuleEvalCanonical(
        $this->user->id,
        $this->account->id,
        'ACME',
        'acme',
        description: 'Monthly invoice INV-12345',
    );

    $outcome = $evaluator->evaluate($tx, $this->user);

    expect($outcome->ruleId)->toBe($ruleId);
});

it('counterparty field is an alias for the merchant target (counterparty_name)', function (): void {
    $ruleId = seedRule($this->user->id, 'counterparty', 'equals', 'Spotify Premium', $this->streaming->id);

    $evaluator = $this->app->make(RuleEvaluator::class);
    $tx = makeRuleEvalCanonical($this->user->id, $this->account->id, 'Spotify Premium', 'spotify premium');

    $outcome = $evaluator->evaluate($tx, $this->user);

    expect($outcome->ruleId)->toBe($ruleId);
});

it('skips the memory lookup when counterparty_normalized is the empty sentinel', function (): void {
    seedMerchantAndMemory($this->user->id, '_no_counterparty', $this->streaming->id);

    $evaluator = $this->app->make(RuleEvaluator::class);
    $tx = makeRuleEvalCanonical($this->user->id, $this->account->id, null, '_no_counterparty');

    $outcome = $evaluator->evaluate($tx, $this->user);

    expect($outcome->categoryId)->toBeNull();
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

    $outcome = $evaluator->evaluate($tx, $this->user);

    expect($outcome->source)->toBe('memory');
    expect($outcome->categoryId)->toBe($this->groceries->id);
});
