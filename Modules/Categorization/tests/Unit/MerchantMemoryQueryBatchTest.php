<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Categorization\Public\Dto\MerchantMemoryDto;
use Modules\Categorization\Public\Services\MerchantMemoryQuery;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Category;

uses(RefreshDatabase::class);

function seedBatchMemory(User $user, Category $category, string $normalized, int $occurrenceCount, ?string $lastSeen): int
{
    $db = app(DatabaseManager::class);
    $now = CarbonImmutable::now()->toDateTimeString();

    $merchantId = (int) $db->connection()->table('merchants')->insertGetId([
        'user_id' => $user->id,
        'name' => $normalized,
        'normalized_name' => $normalized,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return (int) $db->connection()->table('merchant_memories')->insertGetId([
        'user_id' => $user->id,
        'merchant_id' => $merchantId,
        'category_id' => $category->id,
        'occurrence_count' => $occurrenceCount,
        'last_seen_at' => $lastSeen,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'mem-batch',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->otherUser = User::create([
        'username' => 'mem-batch-other',
        'password' => 'opensesame',
        'period_start_day' => 1,
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
    $this->query = app(MerchantMemoryQuery::class);
});

it('returns an empty array when given an empty list of counterparty names', function (): void {
    expect($this->query->forCounterpartiesNormalized($this->user, []))->toBe([]);
});

it('scopes by user_id — memories owned by another user never surface', function (): void {
    seedBatchMemory($this->otherUser, $this->streaming, 'spotify premium', 7, '2026-05-10 12:00:00');

    $result = $this->query->forCounterpartiesNormalized($this->user, ['spotify premium']);

    expect($result)->toBe([]);
});

it('returns a map keyed by the normalized counterparty name for a multi-name lookup', function (): void {
    seedBatchMemory($this->user, $this->streaming, 'spotify premium', 9, '2026-05-12 10:00:00');
    seedBatchMemory($this->user, $this->groceries, 'albert heijn', 4, '2026-05-13 11:00:00');

    $result = $this->query->forCounterpartiesNormalized(
        $this->user,
        ['spotify premium', 'albert heijn'],
    );

    expect($result)->toHaveKey('spotify premium');
    expect($result)->toHaveKey('albert heijn');
    expect($result['spotify premium'])->toBeInstanceOf(MerchantMemoryDto::class);
    expect($result['spotify premium']->categoryId)->toBe($this->streaming->id);
    expect($result['spotify premium']->occurrenceCount)->toBe(9);
    expect($result['albert heijn']->categoryId)->toBe($this->groceries->id);
    expect($result['albert heijn']->occurrenceCount)->toBe(4);
});

it('omits the key entirely (no nulled placeholder) when no memory exists for that name', function (): void {
    seedBatchMemory($this->user, $this->streaming, 'spotify premium', 3, '2026-05-12 10:00:00');

    $result = $this->query->forCounterpartiesNormalized(
        $this->user,
        ['spotify premium', 'unknown merchant'],
    );

    expect($result)->toHaveKey('spotify premium');
    expect($result)->not->toHaveKey('unknown merchant');
});

it('skips empty-string entries on the input list', function (): void {
    seedBatchMemory($this->user, $this->streaming, 'spotify premium', 3, '2026-05-12 10:00:00');

    $result = $this->query->forCounterpartiesNormalized(
        $this->user,
        ['', 'spotify premium', ''],
    );

    expect($result)->toHaveKey('spotify premium');
    expect($result)->not->toHaveKey('');
});
