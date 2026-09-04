<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Categorization\Internal\Jobs\ReapplyRulesJob;
use Modules\Categorization\Models\CategorizationRule;
use Modules\Categorization\Public\Actions\DeleteCategorizationRule;
use Modules\Core\Models\User;
use Modules\Counterparties\Models\Counterparty;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;

// The ledger records what was decided, not what a rule currently says. A
// delete that walked back its own assignments would empty a categorised
// history the moment a reader tidied up their rule list, and nothing about
// the rule row remembers which transactions to put back.

/**
 * @return array{user: User, transaction: Transaction, rule: CategorizationRule, categoryId: int, counterpartyId: int, deductionCategoryId: int}
 */
function ruleDeleteDecidedFixtures(): array
{
    $suffix = bin2hex(random_bytes(4));

    $user = User::query()->create([
        'username' => 'rule-delete-decided-'.$suffix,
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    $account = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'ASN',
        'slug' => 'rule-delete-decided-'.$suffix,
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper(substr($suffix, 0, 8)),
        'default_currency' => 'EUR',
    ]);

    $run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/rule-delete-decided.xml',
        'sha256' => hash('sha256', 'rule-delete-decided-'.$suffix),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    $category = Category::query()->create([
        'user_id' => null,
        'name' => 'Streaming',
        'slug' => 'rule-delete-decided-streaming-'.$suffix,
        'kind' => 'expense',
        'display_order' => 1,
    ]);

    $counterparty = Counterparty::query()->create([
        'user_id' => $user->id,
        'type' => 'merchant',
        'slug' => 'rule-delete-decided-spotify-'.$suffix,
        'display_name' => 'Spotify AB',
    ]);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $deductionCategoryId = (int) $db->connection()->table('tax_deduction_categories')->insertGetId([
        'user_id' => $user->id,
        'name' => 'Subscriptions',
        'short_name' => 'SUB',
        'status' => 'active',
        'sort_order' => 0,
        'created_at' => CarbonImmutable::now(),
        'updated_at' => CarbonImmutable::now(),
    ]);

    $rule = CategorizationRule::query()->create([
        'user_id' => $user->id,
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
    $rule->actions()->create(['position' => 0, 'type' => 'category', 'payload' => ['category_id' => $category->id]]);
    $rule->actions()->create(['position' => 1, 'type' => 'counterparty', 'payload' => ['counterparty_id' => $counterparty->id]]);
    $rule->actions()->create(['position' => 2, 'type' => 'note', 'payload' => ['text' => 'Rule note', 'mode' => 'set']]);
    $rule->actions()->create(['position' => 3, 'type' => 'tax_tag', 'payload' => ['deduction_category_id' => $deductionCategoryId]]);

    $transaction = Transaction::query()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'import_run_id' => $run->id,
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
        'source_row_index' => 1,
        'fingerprint' => hash('sha256', 'rule-delete-decided-tx-'.$suffix),
        'fingerprint_version' => 1,
    ]);

    app(BusDispatcher::class)->dispatchSync(new ReapplyRulesJob($user->id));

    return [
        'user' => $user,
        'transaction' => $transaction,
        'rule' => $rule,
        'categoryId' => (int) $category->id,
        'counterpartyId' => (int) $counterparty->id,
        'deductionCategoryId' => $deductionCategoryId,
    ];
}

/**
 * @return array<string, mixed>
 */
function ruleDeleteDecidedRow(int $transactionId): array
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $row = $db->connection()->table('transactions')
        ->where('id', $transactionId)
        ->first(['category_id', 'counterparty_id', 'note', 'field_provenance']);

    return $row === null ? [] : (array) $row;
}

beforeEach(function (): void {
    $this->fixtures = ruleDeleteDecidedFixtures();
    $this->delete = app(DeleteCategorizationRule::class);
    $this->db = app(DatabaseManager::class);
});

it('leaves the category, counterparty, note and provenance the rule wrote standing after the rule is deleted', function (): void {
    $before = ruleDeleteDecidedRow((int) $this->fixtures['transaction']->id);

    expect($before)->not->toBe([])
        ->and($before['category_id'])->toBe($this->fixtures['categoryId'])
        ->and($before['counterparty_id'])->toBe($this->fixtures['counterpartyId'])
        ->and($before['note'])->toBe('Rule note')
        ->and($before['field_provenance'])->toBeString();

    ($this->delete)($this->fixtures['user'], (int) $this->fixtures['rule']->id);

    expect($this->db->connection()->table('categorization_rules')->where('id', $this->fixtures['rule']->id)->exists())->toBeFalse()
        ->and(ruleDeleteDecidedRow((int) $this->fixtures['transaction']->id))->toBe($before);
});

it('leaves the tax tag the rule applied on the transaction after the rule is deleted', function (): void {
    $transactionId = (int) $this->fixtures['transaction']->id;

    $before = $this->db->connection()->table('tax_transaction_tags')
        ->where('transaction_id', $transactionId)
        ->get(['deduction_category_id'])
        ->all();

    expect($before)->toHaveCount(1);

    ($this->delete)($this->fixtures['user'], (int) $this->fixtures['rule']->id);

    $after = $this->db->connection()->table('tax_transaction_tags')
        ->where('transaction_id', $transactionId)
        ->get(['deduction_category_id'])
        ->all();

    expect($after)->toEqual($before);
});

it('takes the rule\'s own conditions and actions with it and nothing else', function (): void {
    $ruleId = (int) $this->fixtures['rule']->id;

    expect($this->db->connection()->table('rule_conditions')->where('rule_id', $ruleId)->count())->toBe(1)
        ->and($this->db->connection()->table('rule_actions')->where('rule_id', $ruleId)->count())->toBe(4);

    ($this->delete)($this->fixtures['user'], $ruleId);

    expect($this->db->connection()->table('rule_conditions')->where('rule_id', $ruleId)->count())->toBe(0)
        ->and($this->db->connection()->table('rule_actions')->where('rule_id', $ruleId)->count())->toBe(0)
        ->and($this->db->connection()->table('transactions')->where('user_id', $this->fixtures['user']->id)->count())->toBe(1);
});
