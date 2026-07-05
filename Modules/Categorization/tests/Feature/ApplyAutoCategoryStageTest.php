<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Categorization\Internal\Pipeline\ApplyAutoCategoryStage;
use Modules\Categorization\Public\Contracts\AppliesAutoCategory;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Contracts\RecordsTransactions;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'auto-cat-test',
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

    $this->streamingId = DB::table('categories')->insertGetId([
        'user_id' => null,
        'name' => 'Streaming',
        'slug' => 'streaming',
        'kind' => 'expense',
        'display_order' => 100,
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);
});

function makeAutoCanonical(int $userId, int $accountId): CanonicalTransaction
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
        counterpartyName: 'Spotify Premium',
        counterpartyIban: null,
        counterpartyNormalized: 'spotify premium',
        normalizationVersion: 1,
        description: null,
        categoryId: null,
        sourceFormat: 'asn-csv',
        importRunId: 1,
        sourceRowIndex: 0,
        sourceRef: null,
    );
}

/**
 * Seeds a single-condition/single-action rule on the new normalized
 * schema (`categorization_rules` parent + one `rule_conditions` row +
 * one `rule_actions` row) — the flat `field`/`match`/`value`/
 * `category_id` columns were dropped by 13.4-01 (D-01/D-02/D-03).
 */
function seedAutoRule(int $userId, int $categoryId): int
{
    $ruleId = (int) DB::table('categorization_rules')->insertGetId([
        'user_id' => $userId,
        'priority' => 0,
        'combinator' => 'all',
        'hits_count' => 0,
        'active' => true,
        'notes' => null,
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);

    DB::table('rule_conditions')->insert([
        'rule_id' => $ruleId,
        'field' => 'merchant',
        'op' => 'equals',
        'value_type' => 'string',
        'value' => 'Spotify Premium',
        'value2' => null,
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);

    DB::table('rule_actions')->insert([
        'rule_id' => $ruleId,
        'position' => 0,
        'type' => 'category',
        'payload' => json_encode(['category_id' => $categoryId], JSON_THROW_ON_ERROR),
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);

    return $ruleId;
}

it('returns manual outcome when no rule or memory matches', function (): void {
    /** @var ApplyAutoCategoryStage $stage */
    $stage = $this->app->make(ApplyAutoCategoryStage::class);
    $tx = makeAutoCanonical($this->user->id, $this->account->id);

    $outcome = $stage->apply($tx, $this->user);

    expect($outcome->provenance)->toBe('manual');
    expect($outcome->ruleId)->toBeNull();
    expect($outcome->memoryId)->toBeNull();
    expect($outcome->canonical->categoryId)->toBeNull();
    expect($outcome->canonical->autoCategoryProvenance)->toBeNull();
});

it('returns auto outcome with rule provenance when an equals-rule matches', function (): void {
    $ruleId = seedAutoRule($this->user->id, $this->streamingId);

    /** @var ApplyAutoCategoryStage $stage */
    $stage = $this->app->make(ApplyAutoCategoryStage::class);
    $tx = makeAutoCanonical($this->user->id, $this->account->id);

    $outcome = $stage->apply($tx, $this->user);

    expect($outcome->provenance)->toBe('rule');
    expect($outcome->ruleId)->toBe($ruleId);
    expect($outcome->memoryId)->toBeNull();
    expect($outcome->canonical->categoryId)->toBe($this->streamingId);
    expect($outcome->canonical->autoCategoryProvenance)->toBe([
        'source' => 'rule',
        'rule_id' => $ruleId,
        'memory_id' => null,
        'category_id' => $this->streamingId,
    ]);
});

it('returns auto outcome with memory provenance when only memory matches', function (): void {
    $merchantId = (int) DB::table('merchants')->insertGetId([
        'user_id' => $this->user->id,
        'name' => 'spotify premium',
        'normalized_name' => 'spotify premium',
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);
    $memoryId = (int) DB::table('merchant_memories')->insertGetId([
        'user_id' => $this->user->id,
        'merchant_id' => $merchantId,
        'category_id' => $this->streamingId,
        'occurrence_count' => 3,
        'last_seen_at' => CarbonImmutable::now()->toDateTimeString(),
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);

    /** @var ApplyAutoCategoryStage $stage */
    $stage = $this->app->make(ApplyAutoCategoryStage::class);
    $tx = makeAutoCanonical($this->user->id, $this->account->id);

    $outcome = $stage->apply($tx, $this->user);

    expect($outcome->provenance)->toBe('memory');
    expect($outcome->ruleId)->toBeNull();
    expect($outcome->memoryId)->toBe($memoryId);
    expect($outcome->canonical->categoryId)->toBe($this->streamingId);
    expect($outcome->canonical->autoCategoryProvenance)->toBe([
        'source' => 'memory',
        'rule_id' => null,
        'memory_id' => $memoryId,
        'category_id' => $this->streamingId,
    ]);
});

it('returns manual when the evaluator throws (Validation Axis 7 — side-effect-free)', function (): void {
    // Trigger a real RuleEvaluator failure by dropping the
    // categorization_rules table before the stage runs. The evaluator's
    // first query throws a SQL exception; ApplyAutoCategoryStage swallows
    // it (per the side-effect-free invariant) and returns manual outcome.
    DB::statement('DROP TABLE categorization_rules');

    $stage = $this->app->make(ApplyAutoCategoryStage::class);
    $tx = makeAutoCanonical($this->user->id, $this->account->id);

    $outcome = $stage->apply($tx, $this->user);

    expect($outcome->provenance)->toBe('manual');
    expect($outcome->canonical->categoryId)->toBeNull();
    expect($outcome->canonical->autoCategoryProvenance)->toBeNull();
});

it('binds the AppliesAutoCategory contract to ApplyAutoCategoryStage', function (): void {
    $resolved = $this->app->make(AppliesAutoCategory::class);

    expect($resolved)->toBeInstanceOf(ApplyAutoCategoryStage::class);
});

it('persists auto_category_provenance JSON via RecordTransactions when present', function (): void {
    $ruleId = seedAutoRule($this->user->id, $this->streamingId);
    $importRunId = (int) DB::table('import_runs')->insertGetId([
        'user_id' => $this->user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/auto-cat.csv',
        'sha256' => str_repeat('z', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);

    /** @var ApplyAutoCategoryStage $stage */
    $stage = $this->app->make(ApplyAutoCategoryStage::class);
    $tx = makeAutoCanonical($this->user->id, $this->account->id);
    $tx = new CanonicalTransaction(
        userId: $tx->userId,
        accountId: $tx->accountId,
        type: $tx->type,
        postedAt: $tx->postedAt,
        bookedAt: $tx->bookedAt,
        valueDate: $tx->valueDate,
        amountMinor: $tx->amountMinor,
        currency: $tx->currency,
        settledAmountMinor: $tx->settledAmountMinor,
        settledCurrency: $tx->settledCurrency,
        fxRateUsed: $tx->fxRateUsed,
        counterpartyName: $tx->counterpartyName,
        counterpartyIban: $tx->counterpartyIban,
        counterpartyNormalized: $tx->counterpartyNormalized,
        normalizationVersion: $tx->normalizationVersion,
        description: $tx->description,
        categoryId: $tx->categoryId,
        sourceFormat: $tx->sourceFormat,
        importRunId: $importRunId,
        sourceRowIndex: $tx->sourceRowIndex,
        sourceRef: $tx->sourceRef,
    );

    $outcome = $stage->apply($tx, $this->user);

    $recorder = $this->app->make(RecordsTransactions::class);
    $result = $recorder([$outcome->canonical], $this->user);

    expect($result->inserted)->toBe(1);

    $persisted = DB::table('transactions')
        ->where('user_id', $this->user->id)
        ->orderByDesc('id')
        ->first(['category_id', 'auto_category_provenance']);

    expect($persisted)->not->toBeNull();
    expect((int) $persisted->category_id)->toBe($this->streamingId);

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode((string) $persisted->auto_category_provenance, associative: true, flags: JSON_THROW_ON_ERROR);
    expect($decoded['source'] ?? null)->toBe('rule');
    expect($decoded['rule_id'] ?? null)->toBe($ruleId);
    expect($decoded['category_id'] ?? null)->toBe($this->streamingId);
});

it('increments the matched rule hits_count on every rule-fire (Wave 4 deferral closed)', function (): void {
    $ruleId = seedAutoRule($this->user->id, $this->streamingId);

    /** @var ApplyAutoCategoryStage $stage */
    $stage = $this->app->make(ApplyAutoCategoryStage::class);
    $canonical = makeAutoCanonical($this->user->id, $this->account->id);

    $stage->apply($canonical, $this->user);
    $stage->apply($canonical, $this->user);
    $stage->apply($canonical, $this->user);

    $hits = (int) DB::table('categorization_rules')->where('id', $ruleId)->value('hits_count');
    expect($hits)->toBe(3);
});

it('rolls back an earlier rule\'s hits_count bump when a later matched rule fails mid-loop (WR-01)', function (): void {
    $firstRuleId = seedAutoRule($this->user->id, $this->streamingId);
    // A second rule matching the SAME transaction (same merchant), so both
    // fire in the same apply() call and both attempt a hits_count bump.
    $secondRuleId = seedAutoRule($this->user->id, $this->streamingId);

    // A transient SQLite trigger that aborts ONLY the second rule's
    // hits_count UPDATE — simulates a mid-loop failure (e.g. rule 3 of 4
    // in the review's own scenario) without needing to mock the query
    // builder. Laravel's DatabaseManager::transaction() catches the
    // resulting QueryException, ROLLBACKs the WHOLE transaction (undoing
    // the first rule's already-applied increment too), then rethrows —
    // which ApplyAutoCategoryStage's outer try/catch swallows into a
    // manual() outcome.
    DB::statement(sprintf(
        "CREATE TRIGGER wr01_boom BEFORE UPDATE OF hits_count ON categorization_rules
         WHEN NEW.id = %d
         BEGIN SELECT RAISE(ABORT, 'WR-01 simulated mid-loop failure'); END",
        $secondRuleId,
    ));

    try {
        /** @var ApplyAutoCategoryStage $stage */
        $stage = $this->app->make(ApplyAutoCategoryStage::class);
        $canonical = makeAutoCanonical($this->user->id, $this->account->id);

        $outcome = $stage->apply($canonical, $this->user);

        // The stage is side-effect-free on failure (fail-open, T-13.4-18):
        // the whole categorization folds back to manual...
        expect($outcome->provenance)->toBe('manual');

        // ...and the first rule's hits_count bump — which happened BEFORE
        // the second rule's UPDATE aborted, inside the SAME transaction —
        // must be rolled back too, not permanently stuck at 1 while the
        // outcome claims no rule was applied.
        $firstHits = (int) DB::table('categorization_rules')->where('id', $firstRuleId)->value('hits_count');
        expect($firstHits)->toBe(0);
    } finally {
        DB::statement('DROP TRIGGER IF EXISTS wr01_boom');
    }
});
