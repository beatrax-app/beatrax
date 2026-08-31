<?php

declare(strict_types=1);

namespace Modules\Categorization\Database\Seeders\Demo;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Public\Services\CounterpartyKey;

// merchant_memories.merchant_id is FK-constrained to merchants, so a per-user
// merchants row is upserted first. Neither table has an Eloquent model, hence
// the raw query builder.
final class DemoMerchantMemorySeeder
{
    /**
     * @var list<array{merchantName: string, normalizedName: string, categorySlug: string, occurrenceCount: int}>
     */
    private const MEMORIES = [
        ['merchantName' => 'Albert Heijn', 'normalizedName' => 'albert heijn', 'categorySlug' => 'groceries', 'occurrenceCount' => 18],
        ['merchantName' => 'Spotify', 'normalizedName' => 'spotify ab', 'categorySlug' => 'subscriptions-music', 'occurrenceCount' => 3],
        ['merchantName' => 'Netflix', 'normalizedName' => 'netflix international bv', 'categorySlug' => 'subscriptions-streaming', 'occurrenceCount' => 3],
        ['merchantName' => 'NS Reizigers', 'normalizedName' => 'ns reizigers', 'categorySlug' => 'transport-public', 'occurrenceCount' => 24],
        ['merchantName' => 'KPN', 'normalizedName' => 'kpn bv', 'categorySlug' => 'housing-internet', 'occurrenceCount' => 3],
    ];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly CounterpartyKey $counterpartyKey,
        private readonly Clock $clock,
    ) {}

    /**
     * @param  array<string, User>  $users
     */
    public function run(array $users): int
    {
        $primary = $users['demo-1'] ?? null;
        if ($primary === null) {
            return 0;
        }

        $now = $this->clock->now()->toDateTimeString();

        foreach (self::MEMORIES as $row) {
            $categoryId = $this->resolveCategoryId($row['categorySlug']);
            if ($categoryId === null) {
                continue;
            }

            // Keyed through the same producer the transactions get, or the
            // join in RuleEvaluator would miss every seeded merchant for a
            // user with at-rest encryption on.
            $merchantId = $this->ensureMerchant(
                $primary,
                $row['merchantName'],
                $this->counterpartyKey->forNormalized($row['normalizedName'], $primary->id),
                $categoryId,
                $now,
            );
            $this->upsertMemory($primary, $merchantId, $categoryId, $row['occurrenceCount'], $now);
        }

        return (int) $this->db->connection()
            ->table('merchant_memories')
            ->where('user_id', $primary->id)
            ->count();
    }

    private function resolveCategoryId(string $slug): ?int
    {
        $category = Category::query()
            ->withoutGlobalScopes()
            ->where('slug', $slug)
            ->whereNull('user_id')
            ->first(['id']);

        if ($category === null) {
            return null;
        }

        return $category->id;
    }

    private function ensureMerchant(
        User $user,
        string $name,
        string $normalized,
        int $defaultCategoryId,
        string $now,
    ): int {
        $connection = $this->db->connection();

        $existing = $connection->table('merchants')
            ->where('user_id', $user->id)
            ->where('normalized_name', $normalized)
            ->first(['id']);

        if ($existing !== null && is_numeric($existing->id)) {
            return (int) $existing->id;
        }

        return (int) $connection->table('merchants')->insertGetId([
            'user_id' => $user->id,
            'name' => $name,
            'normalized_name' => $normalized,
            'default_category_id' => $defaultCategoryId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function upsertMemory(
        User $user,
        int $merchantId,
        int $categoryId,
        int $occurrenceCount,
        string $now,
    ): void {
        $connection = $this->db->connection();

        $connection->table('merchant_memories')->updateOrInsert(
            [
                'user_id' => $user->id,
                'merchant_id' => $merchantId,
                'category_id' => $categoryId,
            ],
            [
                'occurrence_count' => $occurrenceCount,
                'last_seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );
    }
}
