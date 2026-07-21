<?php

declare(strict_types=1);

namespace Modules\Categorization\Database\Seeders\Demo;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Category;

// merchant_memories.merchant_id is FK-constrained to merchants, so this
// first upserts a per-user merchants row keyed on (user_id,
// normalized_name), then attaches a memory row. Both tables are accessed
// via the raw query builder — neither has an Eloquent model in the project.
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
    ) {}

    /**
     * @param  array<string, User>  $users
     */
    public function run(array $users): int
    {
        $primary = $users['demo-1@beatrax.local'] ?? null;
        if ($primary === null) {
            return 0;
        }

        foreach (self::MEMORIES as $row) {
            $categoryId = $this->resolveCategoryId($row['categorySlug']);
            if ($categoryId === null) {
                continue;
            }

            $merchantId = $this->ensureMerchant($primary, $row['merchantName'], $row['normalizedName'], $categoryId);
            $this->upsertMemory($primary, $merchantId, $categoryId, $row['occurrenceCount']);
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

    private function ensureMerchant(User $user, string $name, string $normalized, int $defaultCategoryId): int
    {
        $connection = $this->db->connection();
        $now = CarbonImmutable::now()->toDateTimeString();

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

    private function upsertMemory(User $user, int $merchantId, int $categoryId, int $occurrenceCount): void
    {
        $connection = $this->db->connection();
        $now = CarbonImmutable::now()->toDateTimeString();

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
