<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Builder;

/**
 * Forward data-backfill (Req 5): losslessly re-expresses every legacy
 * flat `categorization_rules` row — stashed into
 * `_legacy_categorization_rules` by migration 000001 before its flat
 * columns were dropped — as one normalized (parent + 1 condition + 1
 * action) triple in the new schema:
 *
 *   - Parent row (same id, already exists): set `priority = id * 10`
 *     (deterministically preserves original insertion order) and
 *     `combinator = 'all'` (a single condition makes the combinator
 *     moot).
 *   - One `rule_conditions` row: `field`/`value` copied verbatim,
 *     `op` = the legacy `match` value (the enums are identical:
 *     contains/equals/starts_with), `value_type = 'string'`,
 *     `value2 = null`.
 *   - One `rule_actions` row: `position = 0`, `type = 'category'`,
 *     `payload = {"category_id": <legacy category_id>}`.
 *
 * The whole backfill runs inside a single DB transaction so a mid-loop
 * failure leaves the pre-migration state fully intact rather than a
 * partially-migrated rule set. Once every row is backfilled, the
 * `_legacy_categorization_rules` stash table is dropped — this
 * migration is the last consumer of that data.
 */
return new class extends Migration
{
    private ?DatabaseManager $resolvedDb = null;

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

        // The stash table's data has now been fully absorbed into the
        // normalized schema — drop it so it does not linger.
        $this->schema()->dropIfExists('_legacy_categorization_rules');
    }

    public function down(): void
    {
        // Best-effort reverse only: by the time this runs, the
        // `_legacy_categorization_rules` stash (dropped by this
        // migration's own up()) is gone, so the original flat
        // field/match/value/category_id data cannot be losslessly
        // restored here. This clears the normalized rows this
        // migration created and resets the parent's priority/combinator
        // back to their column defaults, so a subsequent re-run of
        // up() (via migrate:rollback then migrate) is idempotent and
        // does not leave duplicate condition/action rows behind.
        $connection = $this->db()->connection($this->getConnection());

        $connection->table('rule_conditions')->delete();
        $connection->table('rule_actions')->delete();
        $connection->table('categorization_rules')->update([
            'priority' => 0,
            'combinator' => 'all',
        ]);
    }

    private function schema(): Builder
    {
        return $this->db()->connection($this->getConnection())->getSchemaBuilder();
    }

    private function db(): DatabaseManager
    {
        if ($this->resolvedDb === null) {
            /** @var DatabaseManager $db */
            $db = Container::getInstance()->make(DatabaseManager::class);
            $this->resolvedDb = $db;
        }

        return $this->resolvedDb;
    }
};
