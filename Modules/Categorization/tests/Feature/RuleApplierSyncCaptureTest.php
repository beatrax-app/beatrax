<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Categorization\Internal\Pipeline\ApplyAutoCategoryStage;
use Modules\Categorization\Internal\Services\RuleApplier;
use Modules\Categorization\Internal\Services\RuleEngine;
use Modules\Categorization\Internal\Services\RuleMatchInput;
use Modules\Categorization\Models\CategorizationRule;
use Modules\Core\Models\User;
use Modules\Counterparties\Models\Counterparty;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Sync\Internal\OpLog\OpLogWriter;

function bindRealOpLogWriterForRuleSync(int $userId): OpLogWriter
{
    $keypair = sodium_crypto_sign_keypair();
    $sk = sodium_crypto_sign_secretkey($keypair);
    $pk = sodium_crypto_sign_publickey($keypair);

    /** @var OpLogWriter $writer */
    $writer = app(OpLogWriter::class, [
        'deviceId' => 'rule-sync-capture-device',
        'userId' => $userId,
        'secretKey' => $sk,
        'publicKey' => $pk,
    ]);

    // SyncCaptureListener resolves OpLogWriter lazily from the container, so
    // the real writer has to be bound or it throws instead of capturing.
    app()->instance(OpLogWriter::class, $writer);

    return $writer;
}

/**
 * @return array{user: User, account: Account, run: ImportRun, ruleCategory: Category, counterparty: Counterparty}
 */
function seedRuleSyncCaptureFixtures(): array
{
    $user = User::query()->create([
        'username' => 'rule-sync-capture-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    $account = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'ASN rule-sync-capture',
        'slug' => 'rule-sync-capture-asn-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
    ]);

    $run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/rule-sync-capture.xml',
        'sha256' => hash('sha256', 'rule-sync-capture-'.bin2hex(random_bytes(8))),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    $ruleCategory = Category::query()->create([
        'user_id' => null,
        'name' => 'Streaming sync-capture',
        'slug' => 'rule-sync-capture-streaming-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'display_order' => 1,
    ]);

    $counterparty = Counterparty::query()->create([
        'user_id' => $user->id,
        'type' => 'merchant',
        'slug' => 'rule-sync-capture-spotify-'.bin2hex(random_bytes(3)),
        'display_name' => 'Spotify AB',
    ]);

    return [
        'user' => $user,
        'account' => $account,
        'run' => $run,
        'ruleCategory' => $ruleCategory,
        'counterparty' => $counterparty,
    ];
}

// Category, counterparty and note only: tax_tag delegates to TagTransaction,
// which dispatches TransactionTagged rather than TransactionMutated and adds
// no op_log row, blurring the "N changed fields, N ops" count.
function makeRuleSyncCaptureRule(int $userId, int $categoryId, int $counterpartyId): CategorizationRule
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
    $rule->actions()->create(['position' => 2, 'type' => 'note', 'payload' => ['text' => 'Sync capture note', 'mode' => 'set']]);

    return $rule;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function makeRuleSyncCaptureTx(User $user, Account $account, ImportRun $run, int $rowIndex, array $overrides = []): Transaction
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
        'fingerprint' => str_pad('rule-sync-capture-'.$rowIndex, 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ], $overrides));
}

it('a re-apply that changes N fields produces exactly N op_log_entries rows via the real capture path', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $fixtures = seedRuleSyncCaptureFixtures();
    bindRealOpLogWriterForRuleSync($fixtures['user']->id);

    makeRuleSyncCaptureRule($fixtures['user']->id, $fixtures['ruleCategory']->id, $fixtures['counterparty']->id);
    $tx = makeRuleSyncCaptureTx($fixtures['user'], $fixtures['account'], $fixtures['run'], 1);

    $engine = app(RuleEngine::class);
    $applier = app(RuleApplier::class);

    $input = new RuleMatchInput(
        counterpartyName: 'Spotify AB',
        description: null,
        settledAmountMinor: -1000,
        settledCurrency: 'EUR',
        postedAt: CarbonImmutable::parse('2026-07-05'),
    );
    $matched = $engine->match($input, $fixtures['user']);

    $changed = $applier->applyAtReapply($matched, $tx->id, $fixtures['user']->id);
    expect($changed)->toHaveCount(3);

    $rows = $db->connection()->table('op_log_entries')
        ->where('user_id', $fixtures['user']->id)
        ->where('table_name', 'transactions')
        ->where('pk', (string) $tx->id)
        ->get();

    // Three values and one provenance map. The stamp is coalesced to a single
    // write per transaction, so a re-apply that changes N fields still costs
    // N + 1 rows rather than 2N — the map it carries already names every field
    // the pass touched.
    expect($rows)->toHaveCount(4);
    expect($rows->pluck('field')->sort()->values()->all())->toBe(['category_id', 'counterparty_id', 'field_provenance', 'note']);
    foreach ($rows as $row) {
        expect($row->op_type)->toBe('set');
    }
});

it('an import-time rule application produces zero op_log_entries rows for the rule-set fields', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $fixtures = seedRuleSyncCaptureFixtures();
    bindRealOpLogWriterForRuleSync($fixtures['user']->id);

    makeRuleSyncCaptureRule($fixtures['user']->id, $fixtures['ruleCategory']->id, $fixtures['counterparty']->id);

    $canonical = new CanonicalTransaction(
        userId: (int) $fixtures['user']->id,
        accountId: (int) $fixtures['account']->id,
        type: 'expense',
        postedAt: CarbonImmutable::parse('2026-07-05'),
        bookedAt: CarbonImmutable::parse('2026-07-05 12:00:00'),
        valueDate: CarbonImmutable::parse('2026-07-05'),
        amountMinor: -1000,
        currency: 'EUR',
        settledAmountMinor: -1000,
        settledCurrency: 'EUR',
        counterpartyName: 'Spotify AB',
        counterpartyIban: null,
        counterpartyNormalized: 'spotify ab',
        normalizationVersion: 1,
        description: null,
        categoryId: null,
        sourceFormat: 'camt053',
        importRunId: (int) $fixtures['run']->id,
        sourceRowIndex: 1,
        sourceRef: null,
    );

    $engine = app(RuleEngine::class);
    $applier = app(RuleApplier::class);
    $matched = $engine->match(RuleMatchInput::fromCanonical($canonical), $fixtures['user']);
    expect($matched)->not->toBe([]);

    // Pure DTO fold — no DB write, no event dispatch.
    $folded = $applier->applyAtImport($matched, $canonical);
    expect($folded->categoryId)->toBe($fixtures['ruleCategory']->id);
    expect($folded->counterpartyId)->toBe($fixtures['counterparty']->id);

    // Full pipeline stage wrapper — still no transactions write, still no capture.
    $stage = app(ApplyAutoCategoryStage::class);
    $outcome = $stage->apply($canonical, $fixtures['user']);
    expect($outcome->provenance)->toBe('rule');

    $rows = $db->connection()->table('op_log_entries')
        ->where('user_id', $fixtures['user']->id)
        ->get();

    expect($rows)->toHaveCount(0);
});
