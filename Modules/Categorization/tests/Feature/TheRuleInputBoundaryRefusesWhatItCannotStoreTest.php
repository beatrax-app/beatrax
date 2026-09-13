<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\Categorization\Public\Actions\CreateCategorizationRule;
use Modules\Categorization\Public\Dto\RuleInput;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Public\ValueObjects\MoneyInput;

uses(RefreshDatabase::class);

// RuleInput carries caller-supplied arrays and makes no shape guarantee beyond
// "list of maps" -- its own comment says the create/update actions validate
// every element field-by-field. Not one of those refusals had ever run.
function rbUser(): User
{
    return User::query()->create([
        'username' => 'rule-boundary-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
}

/**
 * @param  list<array<string, mixed>>  $conditions
 * @param  list<array<string, mixed>>  $actions
 */
function rbInput(array $conditions, array $actions, string $combinator = 'all'): RuleInput
{
    return new RuleInput(
        priority: 10,
        combinator: $combinator,
        active: true,
        notes: null,
        conditions: $conditions,
        actions: $actions,
    );
}

/**
 * @return list<array<string, mixed>>
 */
function rbCondition(): array
{
    return [['field' => 'merchant', 'op' => 'contains', 'value_type' => 'string', 'value' => 'SPOTIFY']];
}

beforeEach(function (): void {
    $this->user = rbUser();
    $this->create = app(CreateCategorizationRule::class);
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    $this->category = Category::create([
        'user_id' => $this->user->id,
        'name' => 'Streaming',
        'slug' => 'rb-streaming-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'display_order' => 1,
    ]);
    $this->action = [['type' => 'category', 'payload' => ['category_id' => $this->category->id]]];
});

it('stores a rule whose every field is one it recognises', function (): void {
    $ruleId = ($this->create)($this->user, rbInput(rbCondition(), $this->action));

    expect($this->db->connection()->table('categorization_rules')->where('id', $ruleId)->exists())->toBeTrue();
});

// Each of these is a refusal that had never been reached. The message is
// asserted because a reader who sent a bad rule has to be told which field.
it('refuses input it cannot store, naming the field it refused', function (array $conditions, array $actions, string $combinator, string $needle): void {
    expect(fn () => ($this->create)($this->user, rbInput($conditions, $actions, $combinator)))
        ->toThrow(InvalidArgumentException::class, $needle);
})->with([
    'combinator outside the enum' => [
        fn () => rbCondition(),
        fn () => [['type' => 'category', 'payload' => ['category_id' => 1]]],
        'xor',
        "invalid combinator 'xor'",
    ],
    'value_type outside the enum' => [
        fn () => [['field' => 'merchant', 'op' => 'contains', 'value_type' => 'colour', 'value' => 'x']],
        fn () => [['type' => 'category', 'payload' => ['category_id' => 1]]],
        'all',
        "invalid value_type 'colour'",
    ],
    'an operator its value_type does not offer' => [
        fn () => [['field' => 'merchant', 'op' => 'between', 'value_type' => 'string', 'value' => 'x']],
        fn () => [['type' => 'category', 'payload' => ['category_id' => 1]]],
        'all',
        "op 'between' is not valid for value_type 'string'",
    ],
    'a condition value that is blank once trimmed' => [
        fn () => [['field' => 'merchant', 'op' => 'contains', 'value_type' => 'string', 'value' => '   ']],
        fn () => [['type' => 'category', 'payload' => ['category_id' => 1]]],
        'all',
        'condition value must not be empty',
    ],
    'between with no second value' => [
        fn () => [['field' => 'merchant', 'op' => 'between', 'value_type' => 'amount', 'value' => '100']],
        fn () => [['type' => 'category', 'payload' => ['category_id' => 1]]],
        'all',
        "op 'between' requires a non-null value2",
    ],
    'an amount typed in euros rather than minor units' => [
        fn () => [['field' => 'merchant', 'op' => '>', 'value_type' => 'amount', 'value' => '12.50']],
        fn () => [['type' => 'category', 'payload' => ['category_id' => 1]]],
        'all',
        "amount condition value '12.50' must be an integer minor-unit string",
    ],
    'a second amount typed in euros rather than minor units' => [
        fn () => [['field' => 'merchant', 'op' => 'between', 'value_type' => 'amount', 'value' => '100', 'value2' => '12.50']],
        fn () => [['type' => 'category', 'payload' => ['category_id' => 1]]],
        'all',
        "amount condition value2 '12.50' must be an integer minor-unit string",
    ],
    'a transaction attribute no condition may name' => [
        fn () => [['field' => 'iban', 'op' => 'contains', 'value_type' => 'string', 'value' => 'x']],
        fn () => [['type' => 'category', 'payload' => ['category_id' => 1]]],
        'all',
        "invalid field 'iban'",
    ],
    'an action type outside the enum' => [
        fn () => rbCondition(),
        fn () => [['type' => 'delete_transaction', 'payload' => []]],
        'all',
        "invalid action type 'delete_transaction'",
    ],
    'a category action naming no category' => [
        fn () => rbCondition(),
        fn () => [['type' => 'category', 'payload' => []]],
        'all',
        'category action requires a category_id',
    ],
    'a category action whose payload is not a map at all' => [
        fn () => rbCondition(),
        fn () => [['type' => 'category', 'payload' => 'category_id=1']],
        'all',
        'category action requires a category_id',
    ],
    'a counterparty action naming no counterparty' => [
        fn () => rbCondition(),
        fn () => [['type' => 'counterparty', 'payload' => ['counterparty_id' => 0]]],
        'all',
        'counterparty action requires a counterparty_id',
    ],
    'a note action with nothing to write' => [
        fn () => rbCondition(),
        fn () => [['type' => 'note', 'payload' => ['text' => "  \t "]]],
        'all',
        'note action requires non-empty text',
    ],
    'an amount bound past the largest amount the app stores' => [
        fn () => [['field' => 'merchant', 'op' => '<', 'value_type' => 'amount', 'value' => str_repeat('9', 40)]],
        fn () => [['type' => 'category', 'payload' => ['category_id' => 1]]],
        'all',
        'is past the largest amount this application stores',
    ],
    'a second amount bound past the largest amount the app stores' => [
        fn () => [['field' => 'merchant', 'op' => 'between', 'value_type' => 'amount', 'value' => '100', 'value2' => str_repeat('9', 40)]],
        fn () => [['type' => 'category', 'payload' => ['category_id' => 1]]],
        'all',
        'is past the largest amount this application stores',
    ],
    'note text carrying a byte that is not UTF-8' => [
        fn () => rbCondition(),
        fn () => [['type' => 'note', 'payload' => ['text' => "caf\xE9 latte"]]],
        'all',
        'note action text is not valid UTF-8',
    ],
    'a tax tag naming no deduction category' => [
        fn () => rbCondition(),
        fn () => [['type' => 'tax_tag', 'payload' => []]],
        'all',
        'tax_tag action requires a deduction_category_id',
    ],
]);

it('asks for a condition and an action in the reader own language rather than raising', function (): void {
    expect(fn () => ($this->create)($this->user, rbInput([], $this->action)))
        ->toThrow(ValidationException::class);

    expect(fn () => ($this->create)($this->user, rbInput(rbCondition(), [])))
        ->toThrow(ValidationException::class);
});

// normalizeInput() runs before the transaction opens, so a refusal has to leave
// the tables as it found them -- no rule row, no orphan condition or action.
it('writes nothing at all when it refuses', function (): void {
    $before = [
        'rules' => $this->db->connection()->table('categorization_rules')->count(),
        'conditions' => $this->db->connection()->table('rule_conditions')->count(),
        'actions' => $this->db->connection()->table('rule_actions')->count(),
    ];

    $refusals = [
        fn () => ($this->create)($this->user, rbInput(rbCondition(), $this->action, 'xor')),
        fn () => ($this->create)($this->user, rbInput([['field' => 'iban', 'op' => 'contains', 'value_type' => 'string', 'value' => 'x']], $this->action)),
        fn () => ($this->create)($this->user, rbInput(rbCondition(), [['type' => 'note', 'payload' => ['text' => '']]])),
        fn () => ($this->create)($this->user, rbInput(rbCondition(), [
            ['type' => 'category', 'payload' => ['category_id' => $this->category->id]],
            ['type' => 'tax_tag', 'payload' => []],
        ])),
    ];

    foreach ($refusals as $refusal) {
        try {
            $refusal();
            throw new RuntimeException('The refusal above did not refuse.');
        } catch (InvalidArgumentException) {
            // The refusal is the subject of the test above; here only its
            // effect on the tables is read.
        }
    }

    expect([
        'rules' => $this->db->connection()->table('categorization_rules')->count(),
        'conditions' => $this->db->connection()->table('rule_conditions')->count(),
        'actions' => $this->db->connection()->table('rule_actions')->count(),
    ])->toBe($before);
});

// A bound is compared against a stored amount, and stored amounts stop at
// MoneyInput::MAX_MINOR. The ceiling is asserted exactly, because RuleEngine
// casts the stored string with (int) and a bound it cannot represent becomes
// PHP_INT_MAX rather than the figure the reader wrote.
it('takes a bound at the ceiling and refuses the one unit past it', function (): void {
    $atCeiling = (string) MoneyInput::MAX_MINOR;
    $pastIt = (string) (MoneyInput::MAX_MINOR + 1);

    $ruleId = ($this->create)($this->user, rbInput(
        [['field' => 'merchant', 'op' => '<', 'value_type' => 'amount', 'value' => $atCeiling]],
        $this->action,
    ));

    expect($this->db->connection()->table('rule_conditions')->where('rule_id', $ruleId)->value('value'))
        ->toBe($atCeiling);

    expect(fn () => ($this->create)($this->user, rbInput(
        [['field' => 'merchant', 'op' => '<', 'value_type' => 'amount', 'value' => $pastIt]],
        $this->action,
    )))->toThrow(InvalidArgumentException::class, 'is past the largest amount this application stores');
});

it('refuses a negative bound past the ceiling as readily as a positive one', function (): void {
    expect(fn () => ($this->create)($this->user, rbInput(
        [['field' => 'merchant', 'op' => '>', 'value_type' => 'amount', 'value' => '-'.str_repeat('9', 40)]],
        $this->action,
    )))->toThrow(InvalidArgumentException::class, 'is past the largest amount this application stores');
});
