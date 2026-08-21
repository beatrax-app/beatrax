<?php

declare(strict_types=1);

namespace Modules\Categorization\Database\Seeders;

use Illuminate\Database\DatabaseManager;
use Modules\Categorization\Models\CategorizationRule;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Category;
use Psr\Log\LoggerInterface;

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
