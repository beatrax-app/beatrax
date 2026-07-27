<?php

declare(strict_types=1);

namespace Modules\Categorization\Database\Seeders\Demo;

use Illuminate\Database\DatabaseManager;
use Modules\Categorization\Public\Actions\CreateCategorizationRule;
use Modules\Core\Models\User;

// Author-side rules for the demo install, covering the merchants the
// seeded ledger repeats most. Written through the public action so the
// stored conditions and actions match what the rule builder produces,
// including its validation of every field.
final class DemoCategorizationRulesSeeder
{
    // categoryPath is resolved against the default category tree by
    // parent/child name; note attaches a second action so the demo shows
    // a multi-action rule alongside the plain category ones.
    /** @var list<array{notes: string, match: string, categoryPath: list<string>, note: ?string, priority: int, active: bool}> */
    private const RULES = [
        [
            'notes' => 'Supermarket runs',
            'match' => 'Albert Heijn',
            'categoryPath' => ['Groceries'],
            'note' => null,
            'priority' => 10,
            'active' => true,
        ],
        [
            'notes' => 'Train travel to the office',
            'match' => 'NS Reizen',
            'categoryPath' => ['Transport', 'Public transport'],
            'note' => 'Commute',
            'priority' => 20,
            'active' => true,
        ],
        [
            'notes' => 'Music subscription',
            'match' => 'Spotify',
            'categoryPath' => ['Subscriptions', 'Music'],
            'note' => null,
            'priority' => 30,
            'active' => true,
        ],
        [
            'notes' => 'Streaming subscription',
            'match' => 'Netflix',
            'categoryPath' => ['Subscriptions', 'Streaming'],
            'note' => null,
            'priority' => 40,
            'active' => true,
        ],
        [
            'notes' => 'Cash machine withdrawals',
            'match' => 'GEA ASN',
            'categoryPath' => ['Cash withdrawal'],
            'note' => null,
            'priority' => 50,
            'active' => false,
        ],
    ];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly CreateCategorizationRule $createRule,
    ) {}

    /**
     * @param  array<string, User>  $users
     */
    public function run(array $users): int
    {
        $primary = $users['demo-1@beatrax.local'] ?? null;
        if ($primary !== null) {
            foreach (self::RULES as $row) {
                $this->upsertRule($primary, $row);
            }
        }

        return $this->db->connection()
            ->table('categorization_rules')
            ->whereIn('user_id', array_map(static fn (User $u): int => $u->id, $users))
            ->count();
    }

    /**
     * @param  array{notes: string, match: string, categoryPath: list<string>, note: ?string, priority: int, active: bool}  $row
     */
    private function upsertRule(User $user, array $row): void
    {
        $existing = $this->db->connection()
            ->table('categorization_rules')
            ->where('user_id', $user->id)
            ->where('notes', $row['notes'])
            ->exists();

        if ($existing) {
            return;
        }

        $categoryId = $this->categoryId($row['categoryPath']);
        if ($categoryId === null) {
            return;
        }

        $actions = [
            ['type' => 'category', 'payload' => ['category_id' => $categoryId]],
        ];

        if ($row['note'] !== null) {
            $actions[] = ['type' => 'note', 'payload' => ['mode' => 'set', 'text' => $row['note']]];
        }

        $this->createRule->__invoke(
            $user,
            $row['priority'],
            'all',
            $row['active'],
            $row['notes'],
            [[
                'field' => 'description',
                'op' => 'contains',
                'value_type' => 'string',
                'value' => $row['match'],
            ]],
            $actions,
        );
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
