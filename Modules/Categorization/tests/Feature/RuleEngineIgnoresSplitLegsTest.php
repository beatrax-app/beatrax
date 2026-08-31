<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Event;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Categorization\Internal\Actions\AssignCategory;
use Modules\Categorization\Internal\Jobs\ReapplyRulesJob;
use Modules\Categorization\Internal\Pipeline\ApplyAutoCategoryStage;
use Modules\Categorization\Internal\Services\RuleApplier;
use Modules\Categorization\Internal\Services\RuleEngine;
use Modules\Categorization\Models\CategorizationRule;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Actions\SaveTransactionSplit;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Ledger\Public\Services\TransactionStatusQuery;
use Modules\Sync\Public\Events\TransactionMutated;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Psr\Log\LoggerInterface;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-07-04 09:00:00');

    $this->user = User::create([
        'username' => 'rules-ignore-legs-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN rules-ignore-legs',
        'slug' => 'rules-ignore-legs-asn-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
    ]);

    $this->run = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/rules-ignore-legs.xml',
        'sha256' => hash('sha256', 'rules-ignore-legs-'.bin2hex(random_bytes(8))),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    $this->groceries = Category::create(['user_id' => null, 'name' => 'Groceries', 'slug' => 'rules-ignore-groceries-'.bin2hex(random_bytes(3)), 'kind' => 'expense', 'display_order' => 1]);
    $this->household = Category::create(['user_id' => null, 'name' => 'Household', 'slug' => 'rules-ignore-household-'.bin2hex(random_bytes(3)), 'kind' => 'expense', 'display_order' => 2]);
    $this->streaming = Category::create(['user_id' => null, 'name' => 'Streaming', 'slug' => 'rules-ignore-streaming-'.bin2hex(random_bytes(3)), 'kind' => 'expense', 'display_order' => 3]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/** @return list<array<string, mixed>> */
function snapshotSplitLegs(DatabaseManager $db, int $transactionId): array
{
    return $db->connection()->table('transaction_splits')
        ->where('transaction_id', $transactionId)
        ->orderBy('id')
        ->get()
        ->map(static fn (object $row): array => (array) $row)
        ->all();
}

it('ApplyAutoCategoryStage leaves a split transaction\'s legs byte-identical even when a rule matches', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    /** @var Transaction $tx */
    $tx = Transaction::create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'type' => 'expense',
        'posted_at' => '2026-07-04',
        'booked_at' => '2026-07-04 12:00:00',
        'value_date' => '2026-07-04',
        'amount_minor' => -8000,
        'currency' => 'EUR',
        'settled_amount_minor' => -8000,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Albert Heijn',
        'counterparty_normalized' => 'albert heijn',
        'normalization_version' => 1,
        'source_format' => 'camt053',
        'import_run_id' => $this->run->id,
        'source_row_index' => 1,
        'fingerprint' => str_pad('rules-ignore-legs-1', 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);

    app(SaveTransactionSplit::class)->save($this->user, $tx->id, [
        ['id' => null, 'category_id' => $this->groceries->id, 'settled_amount_minor' => -6000, 'note' => null],
        ['id' => null, 'category_id' => $this->household->id, 'settled_amount_minor' => -2000, 'note' => null],
    ]);

    // A rule that would fire for this counterparty, onto a third category the
    // split never used — so a clobbered leg would be visible.
    $rule = CategorizationRule::query()->create([
        'user_id' => $this->user->id,
        'priority' => 0,
        'active' => true,
        'combinator' => 'all',
        'notes' => null,
        'hits_count' => 0,
    ]);
    $rule->conditions()->create([
        'field' => 'merchant',
        'op' => 'equals',
        'value_type' => 'string',
        'value' => 'Albert Heijn',
        'value2' => null,
    ]);
    $rule->actions()->create([
        'position' => 0,
        'type' => 'category',
        'payload' => ['category_id' => $this->streaming->id],
    ]);
    $ruleId = $rule->id;

    $before = snapshotSplitLegs($db, $tx->id);
    expect($before)->toHaveCount(2);

    $canonical = new CanonicalTransaction(
        userId: (int) $this->user->id,
        accountId: (int) $this->account->id,
        type: 'expense',
        postedAt: CarbonImmutable::parse('2026-07-04'),
        bookedAt: CarbonImmutable::parse('2026-07-04 12:00:00'),
        valueDate: CarbonImmutable::parse('2026-07-04'),
        amountMinor: -8000,
        currency: 'EUR',
        settledAmountMinor: -8000,
        settledCurrency: 'EUR',
        counterpartyName: 'Albert Heijn',
        counterpartyIban: null,
        counterpartyNormalized: 'albert heijn',
        normalizationVersion: 1,
        description: null,
        categoryId: null,
        sourceFormat: 'camt053',
        importRunId: (int) $this->run->id,
        sourceRowIndex: 2,
        sourceRef: null,
    );

    /** @var ApplyAutoCategoryStage $stage */
    $stage = app(ApplyAutoCategoryStage::class);
    $outcome = $stage->apply($canonical, $this->user);

    expect($outcome->provenance)->toBe('rule');
    expect($outcome->ruleId)->toBe($ruleId);
    expect($outcome->canonical->categoryId)->toBe($this->streaming->id);

    // The persisted legs stay byte-identical even though the rule fired: the
    // stage only ever sees a pre-persistence DTO.
    $after = snapshotSplitLegs($db, $tx->id);
    expect($after)->toBe($before);

    // The stage writes no row of its own except the matched rule's hits_count.
    $parentCategoryId = $db->connection()->table('transactions')->where('id', $tx->id)->value('category_id');
    expect($parentCategoryId)->toBeNull();
});

it('AssignCategory (manual reclassify) only ever writes the parent vestigial category_id — split legs stay untouched', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    /** @var Transaction $tx */
    $tx = Transaction::create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'type' => 'expense',
        'posted_at' => '2026-07-04',
        'booked_at' => '2026-07-04 12:00:00',
        'value_date' => '2026-07-04',
        'amount_minor' => -8000,
        'currency' => 'EUR',
        'settled_amount_minor' => -8000,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Albert Heijn 2',
        'counterparty_normalized' => 'albert heijn 2',
        'normalization_version' => 1,
        'source_format' => 'camt053',
        'import_run_id' => $this->run->id,
        'source_row_index' => 3,
        'fingerprint' => str_pad('rules-ignore-legs-2', 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);

    app(SaveTransactionSplit::class)->save($this->user, $tx->id, [
        ['id' => null, 'category_id' => $this->groceries->id, 'settled_amount_minor' => -6000, 'note' => null],
        ['id' => null, 'category_id' => $this->household->id, 'settled_amount_minor' => -2000, 'note' => null],
    ]);

    $before = snapshotSplitLegs($db, $tx->id);
    expect($before)->toHaveCount(2);

    /** @var AssignCategory $assign */
    $assign = app(AssignCategory::class);
    $assign($tx->id, $this->streaming->id, $this->user);

    $parentCategoryId = $db->connection()->table('transactions')->where('id', $tx->id)->value('category_id');
    expect((int) $parentCategoryId)->toBe($this->streaming->id);

    $after = snapshotSplitLegs($db, $tx->id);
    expect($after)->toBe($before);
});

it('a full ReapplyRulesJob pass leaves a split transaction\'s legs byte-identical and never dispatches TransactionMutated for it', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    /** @var Transaction $tx */
    $tx = Transaction::create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'type' => 'expense',
        'posted_at' => '2026-07-04',
        'booked_at' => '2026-07-04 12:00:00',
        'value_date' => '2026-07-04',
        'amount_minor' => -8000,
        'currency' => 'EUR',
        'settled_amount_minor' => -8000,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Albert Heijn',
        'counterparty_normalized' => 'albert heijn',
        'normalization_version' => 1,
        'source_format' => 'camt053',
        'import_run_id' => $this->run->id,
        'source_row_index' => 4,
        'fingerprint' => str_pad('rules-ignore-legs-3', 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);

    app(SaveTransactionSplit::class)->save($this->user, $tx->id, [
        ['id' => null, 'category_id' => $this->groceries->id, 'settled_amount_minor' => -6000, 'note' => null],
        ['id' => null, 'category_id' => $this->household->id, 'settled_amount_minor' => -2000, 'note' => null],
    ]);

    // A rule that would fire for this counterparty, so the job skipping the
    // split row is structural rather than a no-fire coincidence.
    $rule = CategorizationRule::query()->create([
        'user_id' => $this->user->id,
        'priority' => 0,
        'active' => true,
        'combinator' => 'all',
        'notes' => null,
        'hits_count' => 0,
    ]);
    $rule->conditions()->create([
        'field' => 'merchant',
        'op' => 'equals',
        'value_type' => 'string',
        'value' => 'Albert Heijn',
        'value2' => null,
    ]);
    $rule->actions()->create([
        'position' => 0,
        'type' => 'category',
        'payload' => ['category_id' => $this->streaming->id],
    ]);

    $before = snapshotSplitLegs($db, $tx->id);
    expect($before)->toHaveCount(2);

    Event::fake([TransactionMutated::class]);

    /** @var ReapplyRulesJob $job */
    $job = app(ReapplyRulesJob::class, ['userId' => $this->user->id]);
    $job->handle(
        app(RuleEngine::class),
        app(RuleApplier::class),
        app(TransactionStatusQuery::class),
        app(DatabaseManager::class),
        app(CacheRepository::class),
        app(Clock::class),
        app(LoggerInterface::class),
        app(SensitiveColumnCodec::class),
        app(Session::class),
        app(AppLockKeyService::class),
    );

    // Never even walked — no TransactionMutated for this split parent.
    Event::assertNotDispatched(TransactionMutated::class, fn (TransactionMutated $e): bool => $e->transactionId === $tx->id);

    $after = snapshotSplitLegs($db, $tx->id);
    expect($after)->toBe($before);

    $tx->refresh();
    expect($tx->category_id)->toBeNull();
});
