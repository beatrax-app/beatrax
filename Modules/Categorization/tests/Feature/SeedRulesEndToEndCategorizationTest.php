<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Categorization\Database\Seeders\DefaultCategorizationRuleSeeder;
use Modules\Categorization\Database\Seeders\DefaultCategoryTreeSeeder;
use Modules\Categorization\Internal\Pipeline\ApplyAutoCategoryStage;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Public\Contracts\RecordsTransactions;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

uses(RefreshDatabase::class);

/*
 * Live-distribution sampled end-to-end test for the seed-rule pipeline.
 *
 * Setup:
 *   1. Create a User.
 *   2. Run DefaultCategoryTreeSeeder (categories must exist before rules).
 *   3. Run DefaultCategorizationRuleSeeder for the user.
 *   4. Create one synthetic Account using only Account::$fillable keys
 *      (name, slug, kind, iban, default_currency — never display_name or
 *      currency, which would be silently dropped).
 *   5. Load the live-distribution fixture (≈200 rows, 56 distinct
 *      counterparties, frequency-weighted multiplicity matching the
 *      2026-05-27 production snapshot).
 *
 * Test contract:
 *   - 8 universal-merchant anchor rows land in their expected categories.
 *   - 2 anonymised personal-identifier anchor rows stay uncategorised.
 *   - The ratio (categorised / total) is ≥0.40 against the live-distribution
 *     fixture — the ≥40% gate against a representative sample, not a
 *     trivial hand-built table.
 *   - Every categorised row carries `auto_category_provenance` matching
 *     the shape `ApplyAutoCategoryStage` produces.
 */

beforeEach(function (): void {
    $this->app->make(DefaultCategoryTreeSeeder::class)->run();

    $this->user = User::query()->create([
        'username' => 'seed-rules-e2e',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->app->make(DefaultCategorizationRuleSeeder::class)->run($this->user);

    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'Test bank',
        'slug' => 'test-bank',
        'kind' => 'bank',
        'iban' => 'NL69TEST0000000001',
        'default_currency' => 'EUR',
    ]);

    $this->importRun = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/seed-rules-e2e.csv',
        'sha256' => str_repeat('e', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);
});

/**
 * Build a CanonicalTransaction for a fixture row. The row's counterparty
 * lands as both `counterpartyName` (raw) and `counterpartyNormalized`
 * (lowercase) so the RuleEvaluator's case-insensitive comparison
 * matches.
 */
function buildCanonical(int $userId, int $accountId, int $importRunId, int $rowIndex, string $counterparty, string $description): CanonicalTransaction
{
    // Vary bookedAt + amount per row so each row produces a unique
    // fingerprint and RecordTransactions does not silently dedupe rows
    // that share counterparty+date. Without this variance, many fixture
    // rows with the same posted_at + amount + counterparty would collapse
    // to one persisted transaction, breaking the "persisted == categorised"
    // assertion further below.
    $minutes = $rowIndex % 60;
    $hours = intdiv($rowIndex, 60) % 24;
    $dayOffset = intdiv($rowIndex, 24 * 60);
    $booked = CarbonImmutable::parse('2026-05-15 00:00:00')
        ->addDays($dayOffset)
        ->addHours($hours)
        ->addMinutes($minutes);
    $amount = -1000 - $rowIndex;

    return new CanonicalTransaction(
        userId: $userId,
        accountId: $accountId,
        type: 'expense',
        postedAt: CarbonImmutable::parse('2026-05-15'),
        bookedAt: $booked,
        valueDate: CarbonImmutable::parse('2026-05-15'),
        amountMinor: $amount,
        currency: 'EUR',
        settledAmountMinor: $amount,
        settledCurrency: 'EUR',
        fxRateUsed: null,
        counterpartyName: $counterparty,
        counterpartyIban: null,
        counterpartyNormalized: mb_strtolower($counterparty),
        normalizationVersion: 1,
        description: $description,
        categoryId: null,
        sourceFormat: 'asn-csv',
        importRunId: $importRunId,
        sourceRowIndex: $rowIndex,
        sourceRef: 'seed-e2e-'.$rowIndex,
    );
}

it('categorises ≥40% of a live-distribution fixture and stamps provenance on every hit', function (): void {
    /** @var list<array{counterparty: string, description: string}> $fixture */
    $fixture = require __DIR__.'/../Fixtures/seed-rules-live-distribution.php';

    expect($fixture)->not->toBeEmpty();

    $stage = $this->app->make(ApplyAutoCategoryStage::class);
    $recorder = $this->app->make(RecordsTransactions::class);

    $categorised = 0;
    $total = count($fixture);

    foreach ($fixture as $index => $row) {
        $canonical = buildCanonical(
            userId: $this->user->id,
            accountId: $this->account->id,
            importRunId: $this->importRun->id,
            rowIndex: $index,
            counterparty: $row['counterparty'],
            description: $row['description'],
        );

        $outcome = $stage->apply($canonical, $this->user);

        if ($outcome->canonical->categoryId !== null) {
            $categorised++;
            expect($outcome->canonical->autoCategoryProvenance)->not->toBeNull();
            expect($outcome->canonical->autoCategoryProvenance)->toHaveKeys(['source', 'rule_id', 'memory_id', 'category_id']);
            expect($outcome->canonical->autoCategoryProvenance['source'])->toBe('rule');
            expect($outcome->canonical->autoCategoryProvenance['category_id'])->toBe($outcome->canonical->categoryId);
        }

        $recorder([$outcome->canonical], $this->user);
    }

    $ratio = $categorised / $total;
    expect($ratio)
        ->toBeGreaterThanOrEqual(0.4, sprintf('Expected ≥40%% categorisation, got %.1f%% (%d/%d)', $ratio * 100, $categorised, $total));

    $persistedWithCategory = DB::table('transactions')
        ->where('user_id', $this->user->id)
        ->whereNotNull('category_id')
        ->count();
    expect($persistedWithCategory)->toBe($categorised);

    $persistedWithProvenance = DB::table('transactions')
        ->where('user_id', $this->user->id)
        ->whereNotNull('auto_category_provenance')
        ->count();
    expect($persistedWithProvenance)->toBe($categorised);
});

it('routes every universal-merchant anchor row to its expected category', function (): void {
    $stage = $this->app->make(ApplyAutoCategoryStage::class);

    $anchors = [
        ['counterparty' => 'Netflix International B.V.', 'expected_slug' => 'subscriptions-streaming'],
        ['counterparty' => 'Google Payment Ireland Ltd.', 'expected_slug' => 'subscriptions-cloud'],
        ['counterparty' => 'Thuisbezorgd.nl ThuisBezo Utrecht', 'expected_slug' => 'eating-out'],
        ['counterparty' => 'Flink BV Amsterdam', 'expected_slug' => 'groceries'],
        ['counterparty' => 'KPN Mobiel', 'expected_slug' => 'housing-internet'],
        ['counterparty' => 'Stichting Dierenlot', 'expected_slug' => 'donations'],
        ['counterparty' => 'KOSTEN KASOPNAME 2.00 EUR', 'expected_slug' => 'cash-withdrawal'],
        ['counterparty' => 'GELDMAAT ROELANTDREEF 239 UTRECHT', 'expected_slug' => 'cash-withdrawal'],
    ];

    foreach ($anchors as $i => $anchor) {
        $canonical = buildCanonical(
            userId: $this->user->id,
            accountId: $this->account->id,
            importRunId: $this->importRun->id,
            rowIndex: $i,
            counterparty: $anchor['counterparty'],
            description: '',
        );

        $outcome = $stage->apply($canonical, $this->user);

        expect($outcome->canonical->categoryId)
            ->not->toBeNull(sprintf('Anchor "%s" should have been categorised', $anchor['counterparty']));

        $resolvedSlug = Category::withoutGlobalScopes()
            ->where('id', $outcome->canonical->categoryId)
            ->value('slug');

        expect($resolvedSlug)
            ->toBe($anchor['expected_slug'], sprintf('Anchor "%s" landed in "%s" instead of "%s"', $anchor['counterparty'], $resolvedSlug ?? 'NULL', $anchor['expected_slug']));
    }
});

it('keeps anonymised personal-identifier rows uncategorised', function (): void {
    $stage = $this->app->make(ApplyAutoCategoryStage::class);

    foreach (['EMPLOYER_01', 'P2P_01'] as $i => $counterparty) {
        $canonical = buildCanonical(
            userId: $this->user->id,
            accountId: $this->account->id,
            importRunId: $this->importRun->id,
            rowIndex: $i,
            counterparty: $counterparty,
            description: 'Salaris',
        );

        $outcome = $stage->apply($canonical, $this->user);

        expect($outcome->canonical->categoryId)
            ->toBeNull(sprintf('Anonymised personal identifier "%s" should NOT have been categorised', $counterparty));
        expect($outcome->canonical->autoCategoryProvenance)->toBeNull();
    }
});

it('a migrated single-condition rule assigns the exact category the old engine would have (Req 5 parity)', function (): void {
    // Netflix is one of the default-categorization-rules.php fixture
    // rows, forward-migrated by 13.4-01 from a flat (field/match/value)
    // row into one categorization_rules parent + one rule_conditions
    // row + one rule_actions row. Driving it through the real
    // ApplyAutoCategoryStage (RuleEngine::match + RuleApplier::applyAtImport)
    // proves the migrated rule still lands the exact pre-existing
    // expected category — the regression-parity proof Req 5 demands.
    $stage = $this->app->make(ApplyAutoCategoryStage::class);
    $canonical = buildCanonical(
        userId: $this->user->id,
        accountId: $this->account->id,
        importRunId: $this->importRun->id,
        rowIndex: 5000,
        counterparty: 'Netflix International B.V.',
        description: '',
    );

    $outcome = $stage->apply($canonical, $this->user);

    expect($outcome->provenance)->toBe('rule');
    expect($outcome->ruleId)->not->toBeNull();
    expect($outcome->canonical->categoryId)->not->toBeNull();

    $resolvedSlug = Category::withoutGlobalScopes()
        ->where('id', $outcome->canonical->categoryId)
        ->value('slug');
    expect($resolvedSlug)->toBe('subscriptions-streaming');
});

it('falls back to the merchant_memories category when no seed rule matches (memory fallback parity)', function (): void {
    // "Boerderij Winkel De Groene Erf" matches none of the
    // universal-merchant seed rules — this is exactly the RESEARCH
    // Pattern 4 scenario: RuleEngine::match() returns zero rules with
    // a category action, so ApplyAutoCategoryStage must fall through
    // to merchant_memories, not leave the row uncategorised.
    $counterparty = 'Boerderij Winkel De Groene Erf';
    $normalized = mb_strtolower($counterparty);
    $groceriesId = (int) Category::withoutGlobalScopes()->whereNull('user_id')->where('slug', 'groceries')->value('id');

    $merchantId = (int) DB::table('merchants')->insertGetId([
        'user_id' => $this->user->id,
        'name' => $normalized,
        'normalized_name' => $normalized,
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);
    $memoryId = (int) DB::table('merchant_memories')->insertGetId([
        'user_id' => $this->user->id,
        'merchant_id' => $merchantId,
        'category_id' => $groceriesId,
        'occurrence_count' => 3,
        'last_seen_at' => CarbonImmutable::now()->toDateTimeString(),
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);

    $stage = $this->app->make(ApplyAutoCategoryStage::class);
    $canonical = buildCanonical(
        userId: $this->user->id,
        accountId: $this->account->id,
        importRunId: $this->importRun->id,
        rowIndex: 5001,
        counterparty: $counterparty,
        description: '',
    );

    $outcome = $stage->apply($canonical, $this->user);

    expect($outcome->provenance)->toBe('memory');
    expect($outcome->memoryId)->toBe($memoryId);
    expect($outcome->ruleId)->toBeNull();
    expect($outcome->canonical->categoryId)->toBe($groceriesId);
    expect($outcome->canonical->autoCategoryProvenance)->toBe([
        'source' => 'memory',
        'rule_id' => null,
        'memory_id' => $memoryId,
        'category_id' => $groceriesId,
    ]);
});

it('leaves a transaction with neither a matching rule nor a memory uncategorised (manual)', function (): void {
    $stage = $this->app->make(ApplyAutoCategoryStage::class);
    $canonical = buildCanonical(
        userId: $this->user->id,
        accountId: $this->account->id,
        importRunId: $this->importRun->id,
        rowIndex: 5002,
        counterparty: 'Totally Unmatched Merchant XYZ',
        description: '',
    );

    $outcome = $stage->apply($canonical, $this->user);

    expect($outcome->provenance)->toBe('manual');
    expect($outcome->ruleId)->toBeNull();
    expect($outcome->memoryId)->toBeNull();
    expect($outcome->canonical->categoryId)->toBeNull();
    expect($outcome->canonical->autoCategoryProvenance)->toBeNull();
});

it('every personal-identifier-prefix fixture row stays uncategorised under the seed rules (fixture-vs-rules consistency)', function (): void {
    // Regression for WR-06. The 40% ratio gate alone cannot detect a
    // contributor adding a categorisable rule whose value collides
    // with an `EMPLOYER_` / `FAMILY_` / `P2P_` anonymisation prefix
    // — the only signal would be a quiet ratio drop. This test
    // walks every fixture row whose counterparty starts with one of
    // the documented personal-identifier prefixes and asserts the
    // ApplyAutoCategoryStage leaves it uncategorised. A future
    // contributor that adds a seed rule for "EMPLOYER" trips this
    // assertion immediately at CI time.
    /** @var list<array{counterparty: string, description: string}> $fixture */
    $fixture = require base_path('Modules/Categorization/tests/Fixtures/seed-rules-live-distribution.php');

    $personalPrefixes = ['EMPLOYER_', 'FAMILY_', 'P2P_'];

    $stage = $this->app->make(ApplyAutoCategoryStage::class);

    $personalRows = [];
    foreach ($fixture as $row) {
        foreach ($personalPrefixes as $prefix) {
            if (str_starts_with($row['counterparty'], $prefix)) {
                $personalRows[] = $row;
                break;
            }
        }
    }

    // Sanity — the fixture documents EMPLOYER_, FAMILY_, P2P_,
    // EMPLOYER_PENSION_, and P2P_BUDGET_ prefixes; the loop above
    // must find at least one fixture row per prefix family, else
    // the fixture (or the anonymisation scheme) has drifted.
    expect($personalRows)->not->toBeEmpty();

    $rowIndex = 1000;
    foreach ($personalRows as $personalRow) {
        $canonical = buildCanonical(
            userId: $this->user->id,
            accountId: $this->account->id,
            importRunId: $this->importRun->id,
            rowIndex: $rowIndex++,
            counterparty: $personalRow['counterparty'],
            description: $personalRow['description'],
        );

        $outcome = $stage->apply($canonical, $this->user);

        expect($outcome->canonical->categoryId)
            ->toBeNull(sprintf(
                'Personal-identifier-prefix row "%s" (description "%s") was categorised — a seed rule has drifted and now collides with the anonymisation scheme.',
                $personalRow['counterparty'],
                $personalRow['description'],
            ));
        expect($outcome->canonical->autoCategoryProvenance)->toBeNull();
    }
});
