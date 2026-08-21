<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Modules\Core\Database\Support\ModuleMigration;

// Each legacy flat rule becomes one parent + one condition + one action; the
// legacy `match` enum is copied straight into `op` because the two enums are
// identical. One transaction, so a mid-loop failure leaves the whole rule set
// pre-migration rather than half-normalised.
return new class extends ModuleMigration
{
    public function up(): void
    {
        $connection = $this->db()->connection($this->getConnection());

        $connection->transaction(function () use ($connection): void {
            /** @var iterable<object{id: int|string, user_id: int|string|null, field: string, match: string, value: string, category_id: int|string, active: mixed, hits_count: mixed, notes: string|null, created_at: string|null, updated_at: string|null}> $legacyRules */
            $legacyRules = $connection->table('_legacy_categorization_rules')->get([
                'id', 'user_id', 'field', 'match', 'value', 'category_id', 'active', 'hits_count', 'notes', 'created_at', 'updated_at',
            ]);

            foreach ($legacyRules as $row) {
                $ruleId = (int) $row->id;

                // Deriving priority from the id preserves the legacy order.
                $connection->table('categorization_rules')->where('id', $ruleId)->update([
                    'priority' => $ruleId * 10,
                    'combinator' => 'all',
                ]);

                $connection->table('rule_conditions')->insert([
                    'rule_id' => $ruleId,
                    'field' => $row->field,
                    'op' => $row->match,
                    'value_type' => 'string',
                    'value' => $row->value,
                    'value2' => null,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ]);

                $connection->table('rule_actions')->insert([
                    'rule_id' => $ruleId,
                    'position' => 0,
                    'type' => 'category',
                    'payload' => json_encode(['category_id' => (int) $row->category_id]),
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ]);
            }
        });

        $this->schema()->dropIfExists('_legacy_categorization_rules');
    }

    public function down(): void
    {
        // Deliberately a no-op. Nothing marks a rule_conditions/rule_actions
        // row as backfilled here rather than authored by a user afterwards,
        // so a reversal would delete real rules; and up() already dropped the
        // stash, so the flat rows cannot be restored either way.
    }
};
