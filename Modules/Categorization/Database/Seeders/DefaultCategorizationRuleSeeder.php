<?php

declare(strict_types=1);

namespace Modules\Categorization\Database\Seeders;

use Illuminate\Database\DatabaseManager;
use Modules\Categorization\Models\CategorizationRule;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Category;
use Psr\Log\LoggerInterface;

/**
 * Idempotent per-user seeder that installs the universal-merchant
 * categorization rule set on a fresh `beatrax:install`.
 *
 * Per-user because `RuleEvaluator` scopes its rule pull by
 * `where('user_id', $userId)` with no NULL fallback — a global rule
 * (`user_id IS NULL`) would never fire. The fixture
 * (`default-categorization-rules.php`) carries every rule definition;
 * this seeder maps each rule's category slug to the corresponding
 * `categories.id` row in the global default tree
 * (`categories.user_id IS NULL`, seeded by `DefaultCategoryTreeSeeder`)
 * and inserts the rule via `firstOrCreate` keyed on
 * `(user_id, field, match, value)` — the UNIQUE database constraint
 * guarantees re-running the seeder produces zero duplicate rows AND
 * preserves any existing row's `hits_count` and `active` columns.
 * `firstOrCreate` is used deliberately and never the update-on-conflict
 * variant: a future refactor that reset `hits_count` would erase real
 * usage data, and the unit-test suite locks that semantic.
 *
 * Defensive slug lookup: if the fixture references a category slug that
 * does not resolve at seed time (a stale fixture row whose slug was
 * removed from the default tree), the seeder logs a warning and skips
 * that single row — it never aborts the whole seed. Onboarding stays
 * resilient to fixture drift.
 *
 * Not extending `Illuminate\Database\Seeder` deliberately: this seeder
 * is a service called from a listener, never from `db:seed`. Skipping
 * the framework base class keeps the constructor DI-clean.
 */
final class DefaultCategorizationRuleSeeder
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly LoggerInterface $logger,
    ) {}

    public function run(User $user): void
    {
        /** @var list<array{category: string, field: string, match: string, value: string}> $fixture */
        $fixture = require __DIR__.'/default-categorization-rules.php';

        $this->db->connection()->transaction(function () use ($user, $fixture): void {
            foreach ($fixture as $row) {
                $slug = $row['category'];
                $categoryId = Category::withoutGlobalScopes()
                    ->whereNull('user_id')
                    ->where('slug', $slug)
                    ->value('id');

                if ($categoryId === null) {
                    $this->logger->warning(
                        'DefaultCategorizationRuleSeeder skipped fixture row — category slug unresolved.',
                        [
                            'user_id' => $user->id,
                            'category_slug' => $slug,
                            'field' => $row['field'],
                            'match' => $row['match'],
                            'value' => $row['value'],
                        ],
                    );

                    continue;
                }

                CategorizationRule::withoutGlobalScopes()->firstOrCreate(
                    [
                        'user_id' => $user->id,
                        'field' => $row['field'],
                        'match' => $row['match'],
                        'value' => $row['value'],
                    ],
                    [
                        'category_id' => (int) $categoryId,
                        'active' => true,
                    ],
                );
            }
        });
    }
}
