<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Categorization\Internal\Services\RuleEvaluator;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

// RuleEvaluator is only the merchant_memories fallback lookup; categorization_rules
// matching lives in RuleEngineConditionMatchingTest and RuleEngineOrderingTest.

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

function seedMemoryForMerchant(int $userId, int $merchantId, int $categoryId, int $occurrenceCount, string $lastSeenAt): int
{
    return (int) DB::table('merchant_memories')->insertGetId([
        'user_id' => $userId,
        'merchant_id' => $merchantId,
        'category_id' => $categoryId,
        'occurrence_count' => $occurrenceCount,
        'last_seen_at' => $lastSeenAt,
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);
}

function seedMerchantOnly(int $userId, string $normalizedName): int
{
    return (int) DB::table('merchants')->insertGetId([
        'user_id' => $userId,
        'name' => $normalizedName,
        'normalized_name' => $normalizedName,
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);
}

// last_seen_at is the primary key of the ordering and occurrence_count only
// breaks a tie on it. Written with both rows stamped `now()`, the primary key
// ties and the count is the only thing under test — the assertion held whichever
// way round the two columns were listed.
it('ranks memories on last_seen_at first, so a stale count of 10 loses to a fresher 2', function (): void {
    $merchantId = seedMerchantOnly($this->user->id, 'spotify premium');

    seedMemoryForMerchant($this->user->id, $merchantId, $this->groceries->id, 10, '2026-06-01 10:00:00');
    $fresher = seedMemoryForMerchant($this->user->id, $merchantId, $this->streaming->id, 2, '2026-08-27 10:00:00');

    $evaluator = $this->app->make(RuleEvaluator::class);
    $tx = makeRuleEvalCanonical($this->user->id, $this->account->id, 'Spotify Premium', 'spotify premium');

    $row = $evaluator->lookupMemory($tx, $this->user->id);

    expect($row)->not->toBeNull();
    expect((int) $row->id)->toBe($fresher);
    expect((int) $row->category_id)->toBe($this->streaming->id);
});

it('falls back to the highest occurrence_count only where two memories were last seen at the same instant', function (): void {
    $merchantId = seedMerchantOnly($this->user->id, 'spotify premium');

    seedMemoryForMerchant($this->user->id, $merchantId, $this->streaming->id, 2, '2026-08-27 10:00:00');
    $strongest = seedMemoryForMerchant($this->user->id, $merchantId, $this->groceries->id, 10, '2026-08-27 10:00:00');

    $evaluator = $this->app->make(RuleEvaluator::class);
    $tx = makeRuleEvalCanonical($this->user->id, $this->account->id, 'Spotify Premium', 'spotify premium');

    $row = $evaluator->lookupMemory($tx, $this->user->id);

    expect($row)->not->toBeNull();
    expect((int) $row->id)->toBe($strongest);
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

it('lets the newest correction outrank a long-standing memory', function (): void {
    // AssignCategory's docblock says overriding a memory-provenance category
    // needs no divergence prompt because "merchant memory relearns on its own".
    // Ranked on the occurrence count alone it did not: a correction landed at 1
    // and the old memory kept winning at 18, so the reader corrected a merchant,
    // imported again, and silently got the wrong category back.
    $seeded = seedMerchantAndMemory($this->user->id, 'albert heijn', $this->streaming->id, occurrenceCount: 18);

    DB::table('merchant_memories')
        ->where('id', $seeded['memory_id'])
        ->update(['last_seen_at' => '2026-08-01 10:00:00']);

    $correctedId = (int) DB::table('merchant_memories')->insertGetId([
        'user_id' => $this->user->id,
        'merchant_id' => $seeded['merchant_id'],
        'category_id' => $this->groceries->id,
        'occurrence_count' => 1,
        'last_seen_at' => '2026-08-27 10:00:00',
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);

    $evaluator = $this->app->make(RuleEvaluator::class);
    $tx = makeRuleEvalCanonical($this->user->id, $this->account->id, 'Albert Heijn', 'albert heijn');

    $row = $evaluator->lookupMemory($tx, $this->user->id);

    expect($row)->not->toBeNull();
    expect((int) $row->id)->toBe($correctedId);
    expect((int) $row->category_id)->toBe($this->groceries->id);
});
