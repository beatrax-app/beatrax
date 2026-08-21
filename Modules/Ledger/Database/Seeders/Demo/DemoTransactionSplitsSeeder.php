<?php

declare(strict_types=1);

namespace Modules\Ledger\Database\Seeders\Demo;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Actions\SaveTransactionSplit;

// Written through the sole mutator, so the demo splits obey the same
// sum-to-parent rule the app enforces.
final class DemoTransactionSplitsSeeder
{
    // Legs are minor units of the parent's settled amount and must sum to it
    // exactly; the mutator rejects any drift.
    /** @var list<array{descriptionMatch: string, legs: list<array{categoryPath: list<string>, minor: int, note: ?string}>}> */
    private const SPLITS = [
        [
            'descriptionMatch' => 'MEDIAMARKT UTRECHT',
            'legs' => [
                ['categoryPath' => ['Subscriptions', 'Cloud / Software'], 'minor' => -2450, 'note' => 'Software licence'],
                ['categoryPath' => ['Personal care'], 'minor' => -10000, 'note' => 'Monitor'],
            ],
        ],
        [
            'descriptionMatch' => 'HEMA bv Utrecht',
            'legs' => [
                ['categoryPath' => ['Groceries'], 'minor' => -1105, 'note' => null],
                ['categoryPath' => ['Personal care'], 'minor' => -1000, 'note' => null],
            ],
        ],
    ];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly SaveTransactionSplit $splitWriter,
    ) {}

    /**
     * @param  array<string, User>  $users
     */
    public function run(array $users): int
    {
        $primary = $users['demo-1@beatrax.local'] ?? null;
        if ($primary !== null) {
            foreach (self::SPLITS as $row) {
                $this->applySplit($primary, $row);
            }
        }

        return $this->db->connection()
            ->table('transaction_splits')
            ->whereIn('user_id', array_map(static fn (User $u): int => $u->id, $users))
            ->count();
    }

    /**
     * @param  array{descriptionMatch: string, legs: list<array{categoryPath: list<string>, minor: int, note: ?string}>}  $row
     */
    private function applySplit(User $user, array $row): void
    {
        $parent = $this->db->connection()
            ->table('transactions')
            ->where('user_id', $user->id)
            ->where('description', $row['descriptionMatch'])
            ->orderByDesc('booked_at')
            ->first(['id', 'settled_amount_minor']);

        if ($parent === null) {
            return;
        }

        $parentId = is_numeric($parent->id) ? (int) $parent->id : 0;
        if ($parentId === 0 || $this->alreadySplit($user, $parentId)) {
            return;
        }

        // A leg total that misses the parent would abort the whole seeder
        // run, so a demo row whose amount has drifted is skipped instead.
        $legTotal = array_sum(array_column($row['legs'], 'minor'));
        if ($legTotal !== (is_numeric($parent->settled_amount_minor) ? (int) $parent->settled_amount_minor : 0)) {
            return;
        }

        $legs = [];
        foreach ($row['legs'] as $leg) {
            $categoryId = $this->categoryId($leg['categoryPath']);
            // An unknown category path means this demo row cannot be split
            // at all, so the whole set is abandoned rather than half-built.
            if ($categoryId === null) {
                $legs = [];
                break;
            }
            $legs[] = [
                'id' => null,
                'category_id' => $categoryId,
                'settled_amount_minor' => $leg['minor'],
                'note' => $leg['note'],
            ];
        }

        if ($legs !== []) {
            $this->splitWriter->save($user, $parentId, $legs);
        }
    }

    private function alreadySplit(User $user, int $transactionId): bool
    {
        return $this->db->connection()
            ->table('transaction_splits')
            ->where('user_id', $user->id)
            ->where('transaction_id', $transactionId)
            ->exists();
    }

    /**
     * @param  list<string>  $path
     */
    private function categoryId(array $path): ?int
    {
        $parentId = null;
        foreach ($path as $name) {
            $query = $this->db->connection()
                ->table('categories')
                ->where('name', $name);

            $parentId === null
                ? $query->whereNull('parent_id')
                : $query->where('parent_id', $parentId);

            $found = $query->value('id');
            if (! is_numeric($found)) {
                return null;
            }
            $parentId = (int) $found;
        }

        return $parentId;
    }
}
