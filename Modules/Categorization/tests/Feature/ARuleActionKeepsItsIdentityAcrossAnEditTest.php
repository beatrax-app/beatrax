<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Categorization\Internal\Http\Livewire\RuleFormModal;
use Modules\Categorization\Public\Actions\CreateCategorizationRule;
use Modules\Categorization\Public\Actions\UpdateCategorizationRule;
use Modules\Categorization\Public\Dto\RuleInput;
use Modules\Core\Models\User;
use Modules\Counterparties\Models\Counterparty;
use Modules\Ledger\Models\Category;

// A save that drops every action row and re-inserts reads the same one save
// later, so only the row ids tell the two writes apart. The open form carries
// those ids back out, and a renumbered set makes the next save land on rows
// the rule no longer holds.

function ruleActionIdentityUser(string $suffix): User
{
    return User::query()->create([
        'username' => 'rule-action-identity-'.$suffix.'-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
}

/**
 * @return list<array{id: int, type: string, payload: string}>
 */
function ruleActionIdentityRows(int $ruleId): array
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $rows = $db->connection()->table('rule_actions')
        ->where('rule_id', $ruleId)
        ->orderBy('position')
        ->orderBy('id')
        ->get(['id', 'type', 'payload']);

    return array_values($rows->map(static fn (object $row): array => [
        'id' => is_numeric($row->id) ? (int) $row->id : 0,
        'type' => is_string($row->type) ? $row->type : '',
        'payload' => is_string($row->payload) ? $row->payload : '',
    ])->all());
}

/**
 * @return list<int>
 */
function ruleActionIdentityIds(int $ruleId): array
{
    return array_column(ruleActionIdentityRows($ruleId), 'id');
}

beforeEach(function (): void {
    $this->user = ruleActionIdentityUser('primary');
    $this->actingAs($this->user);

    $suffix = bin2hex(random_bytes(3));

    $this->streaming = Category::create([
        'user_id' => null,
        'name' => 'Streaming',
        'slug' => 'rule-action-identity-streaming-'.$suffix,
        'kind' => 'expense',
        'display_order' => 1,
    ]);

    $this->music = Category::create([
        'user_id' => null,
        'name' => 'Music',
        'slug' => 'rule-action-identity-music-'.$suffix,
        'kind' => 'expense',
        'display_order' => 2,
    ]);

    $this->counterparty = Counterparty::create([
        'user_id' => $this->user->id,
        'type' => 'merchant',
        'slug' => 'rule-action-identity-spotify-'.$suffix,
        'display_name' => 'Spotify',
        'merchant_name' => 'SPOTIFY',
    ]);

    $this->create = app(CreateCategorizationRule::class);
    $this->update = app(UpdateCategorizationRule::class);

    $this->ruleId = ($this->create)($this->user, new RuleInput(
        priority: 10,
        combinator: 'all',
        active: true,
        notes: null,
        conditions: [
            ['field' => 'merchant', 'op' => 'contains', 'value_type' => 'string', 'value' => 'SPOTIFY'],
        ],
        actions: [
            ['type' => 'category', 'payload' => ['category_id' => $this->streaming->id]],
            ['type' => 'counterparty', 'payload' => ['counterparty_id' => $this->counterparty->id]],
        ],
    ));
});

it('re-saves a rule whose actions did not change without renumbering a single one of them', function (): void {
    $before = ruleActionIdentityRows($this->ruleId);
    expect($before)->toHaveCount(2);

    ($this->update)($this->user, $this->ruleId, new RuleInput(
        priority: 20,
        combinator: 'any',
        active: true,
        notes: 'edited',
        conditions: [
            ['field' => 'merchant', 'op' => 'contains', 'value_type' => 'string', 'value' => 'SPOTIFY'],
        ],
        actions: [
            ['id' => $before[0]['id'], 'type' => 'category', 'payload' => ['category_id' => $this->streaming->id]],
            ['id' => $before[1]['id'], 'type' => 'counterparty', 'payload' => ['counterparty_id' => $this->counterparty->id]],
        ],
    ));

    expect(ruleActionIdentityRows($this->ruleId))->toBe($before);
});

it('updates a kept action in place, drops only the one the edit removed and mints an id only for the one it added', function (): void {
    $before = ruleActionIdentityRows($this->ruleId);
    expect($before)->toHaveCount(2);

    $keptId = $before[0]['id'];
    $droppedId = $before[1]['id'];

    ($this->update)($this->user, $this->ruleId, new RuleInput(
        priority: 10,
        combinator: 'all',
        active: true,
        notes: null,
        conditions: [
            ['field' => 'merchant', 'op' => 'contains', 'value_type' => 'string', 'value' => 'SPOTIFY'],
        ],
        actions: [
            ['id' => $keptId, 'type' => 'category', 'payload' => ['category_id' => $this->music->id]],
            ['type' => 'note', 'payload' => ['text' => 'Monthly subscription', 'mode' => 'set']],
        ],
    ));

    $after = ruleActionIdentityRows($this->ruleId);
    expect($after)->toHaveCount(2);

    $kept = array_values(array_filter($after, static fn (array $row): bool => $row['id'] === $keptId));
    expect($kept)->toHaveCount(1)
        ->and($kept[0]['type'])->toBe('category')
        ->and($kept[0]['payload'])->toContain((string) $this->music->id);

    $ids = array_column($after, 'id');
    expect($ids)->not->toContain($droppedId);

    $minted = array_values(array_diff($ids, [$keptId]));
    expect($minted)->toHaveCount(1)
        ->and($minted[0])->not->toBe($droppedId);
});

it('carries the stored action ids out through the rule form and back, so the reader\'s own edit preserves them', function (): void {
    $before = ruleActionIdentityIds($this->ruleId);
    expect($before)->toHaveCount(2);

    Livewire::test(RuleFormModal::class)
        ->call('open', ruleId: $this->ruleId)
        ->assertSet('actions.0.id', $before[0])
        ->assertSet('actions.1.id', $before[1])
        ->set('actions.0.category_id', $this->music->id)
        ->call('save')
        ->assertSet('errorGeneral', '');

    expect(ruleActionIdentityIds($this->ruleId))->toBe($before);
});
