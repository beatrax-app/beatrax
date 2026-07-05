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
 * and creates each rule as one `CategorizationRule` parent
 * (`combinator = 'all'`, gap-numbered `priority`, `active = true`) plus
 * one `rule_conditions` row (`field`/`op`/`value_type = 'string'`/
 * `value`) and one `rule_actions` row (`type = 'category'`,
 * `payload = {category_id}`) — the new normalized shape (13.4-01,
 * D-01/D-02/D-03).
 *
 * Idempotency: re-running the seeder must not create duplicate rules.
 * Since the UNIQUE(user_id, field, match, value) constraint that
 * enforced this at the DB layer moved off the parent table with the
 * flat columns, idempotency is now checked explicitly — a rule
 * "already exists" when this user owns a CategorizationRule with a
 * matching rule_conditions row (field/op/value_type/value). When found,
 * the row is skipped entirely, preserving that existing rule's
 * `hits_count`/`active`/`priority` exactly as
 * `firstOrCreate` did before: a future refactor that reset those
 * columns would erase real usage data, and the test suite locks that
 * semantic.
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
            $priority = 0;

            foreach ($fixture as $row) {
                $priority += 10;

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

                $alreadyExists = CategorizationRule::withoutGlobalScopes()
                    ->where('user_id', $user->id)
                    ->whereHas('conditions', function ($query) use ($row): void {
                        $query->where('field', $row['field'])
                            ->where('op', $row['match'])
                            ->where('value_type', 'string')
                            ->where('value', $row['value']);
                    })
                    ->exists();

                if ($alreadyExists) {
                    continue;
                }

                $rule = CategorizationRule::withoutGlobalScopes()->create([
                    'user_id' => $user->id,
                    'priority' => $priority,
                    'combinator' => 'all',
                    'active' => true,
                    'hits_count' => 0,
                ]);

                $rule->conditions()->create([
                    'field' => $row['field'],
                    'op' => $row['match'],
                    'value_type' => 'string',
                    'value' => $row['value'],
                    'value2' => null,
                ]);

                $rule->actions()->create([
                    'position' => 0,
                    'type' => 'category',
                    'payload' => ['category_id' => (int) $categoryId],
                ]);
            }
        });
    }
}
