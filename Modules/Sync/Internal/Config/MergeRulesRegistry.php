<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Config;

/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
final class MergeRulesRegistry
{
    // Memoized: strategyFor()/requiredCreateColumns() are called once per
    // (pk, field) in the replayer's CREATE and SET loops, so re-allocating the
    // full literal array on every call wastes work in a merge of thousands of
    // ops. Built once, then reused.
    /** @var array<string, array<string, mixed>>|null */
    private ?array $rules = null;

    /**
     * @return array<string, array<string, mixed>>
     */
    public function rules(): array
    {
        if ($this->rules !== null) {
            return $this->rules;
        }

        return $this->rules = [
            'transactions' => [
                'category_id' => ['strategy' => 'lww', 'nullable' => true],
                'note' => ['strategy' => 'lww', 'nullable' => true],
                'counterparty_id' => ['strategy' => 'lww', 'nullable' => true],
                'type' => ['strategy' => 'lww', 'nullable' => false],
                'status' => ['strategy' => 'lww', 'nullable' => false],
                '_delete_wins' => true,
                // NOT NULL columns without defaults in transactions (status has default 'cleared',
                // payment_type has default 'unknown' — omitted from required list).
                '_create_required' => [
                    'type',
                    'account_id',
                    'amount_minor',
                    'currency',
                    'settled_amount_minor',
                    'settled_currency',
                    'counterparty_normalized',
                    'normalization_version',
                    'source_format',
                    'import_run_id',
                    'source_row_index',
                    'fingerprint',
                    'fingerprint_version',
                ],
            ],
            'merchant_memories' => [
                'occurrence_count' => ['strategy' => 'g_counter', 'nullable' => false],
                'last_seen_at' => ['strategy' => 'lww', 'nullable' => true],
                '_delete_wins' => true,
                '_create_required' => ['counterparty_normalized', 'occurrence_count'],
            ],
            'merchant_aliases' => [
                'generalized_pattern' => ['strategy' => 'lww', 'nullable' => true],
                'friendly_name' => ['strategy' => 'lww', 'nullable' => true],
                'merged_from' => ['strategy' => 'or_set', 'nullable' => true],
                '_delete_wins' => true,
                '_create_required' => ['counterparty_normalized'],
            ],
            // categorization_rules is the PARENT of the multi-condition/
            // multi-action rules engine (see rule_conditions/rule_actions
            // below). Rule-authoring sync is out of scope for now — no
            // mutation dispatches a sync event yet, so this is forward-prepared.
            'categorization_rules' => [
                'priority' => ['strategy' => 'lww', 'nullable' => false],
                'combinator' => ['strategy' => 'lww', 'nullable' => false],
                'active' => ['strategy' => 'lww', 'nullable' => false],
                'notes' => ['strategy' => 'lww', 'nullable' => true],
                'hits_count' => ['strategy' => 'lww', 'nullable' => false],
                '_delete_wins' => true,
                '_create_required' => ['priority', 'combinator', 'active', 'hits_count'],
            ],
            // Condition child table; cross-checked against the
            // rule_conditions migration in Modules/Categorization.
            'rule_conditions' => [
                'field' => ['strategy' => 'lww', 'nullable' => false],
                'op' => ['strategy' => 'lww', 'nullable' => false],
                'value_type' => ['strategy' => 'lww', 'nullable' => false],
                'value' => ['strategy' => 'lww', 'nullable' => false],
                'value2' => ['strategy' => 'lww', 'nullable' => true],
                '_delete_wins' => true,
                '_create_required' => ['rule_id', 'field', 'op', 'value_type', 'value'],
            ],
            // Action child table; cross-checked against the rule_actions
            // migration in Modules/Categorization.
            'rule_actions' => [
                'position' => ['strategy' => 'lww', 'nullable' => false],
                'type' => ['strategy' => 'lww', 'nullable' => false],
                'payload' => ['strategy' => 'lww', 'nullable' => false],
                '_delete_wins' => true,
                '_create_required' => ['rule_id', 'position', 'type', 'payload'],
            ],
            'counterparties' => [
                'display_name' => ['strategy' => 'lww', 'nullable' => true],
                'type' => ['strategy' => 'lww', 'nullable' => false],
                'website' => ['strategy' => 'lww', 'nullable' => true],
                'logo_url' => ['strategy' => 'lww', 'nullable' => true],
                '_delete_wins' => true,
                '_create_required' => ['counterparty_normalized', 'type'],
            ],
            'pots' => [
                'name' => ['strategy' => 'lww', 'nullable' => false],
                'target_amount_minor' => ['strategy' => 'lww', 'nullable' => true],
                'currency' => ['strategy' => 'lww', 'nullable' => false],
                '_delete_wins' => true,
                '_create_required' => ['name', 'currency'],
            ],
            'goals' => [
                'name' => ['strategy' => 'lww', 'nullable' => false],
                'target_amount_minor' => ['strategy' => 'lww', 'nullable' => false],
                'currency' => ['strategy' => 'lww', 'nullable' => false],
                'target_month' => ['strategy' => 'lww', 'nullable' => true],
                '_delete_wins' => true,
                '_create_required' => ['name', 'target_amount_minor', 'currency'],
            ],
            'accounts' => [
                'name' => ['strategy' => 'lww', 'nullable' => false],
                'default_currency' => ['strategy' => 'lww', 'nullable' => false],
                '_delete_wins' => false,
                '_create_required' => ['name', 'kind', 'iban', 'default_currency'],
            ],
            'categories' => [
                'name' => ['strategy' => 'lww', 'nullable' => false],
                'slug' => ['strategy' => 'lww', 'nullable' => false],
                'kind' => ['strategy' => 'lww', 'nullable' => false],
                '_delete_wins' => true,
                '_create_required' => ['name', 'slug', 'kind'],
            ],
            // `transaction_split_id` replays so a leg-scoped tax tag carries
            // its leg scope on a peer — without it, a per-leg deduction would
            // collapse into a whole-transaction tag and corrupt exported tax
            // amounts. `transaction_id` is the only NOT-NULL-without-default column.
            'tax_transaction_tags' => [
                'transaction_split_id' => ['strategy' => 'lww', 'nullable' => true],
                'deduction_category_id' => ['strategy' => 'lww', 'nullable' => true],
                'tax_year_override' => ['strategy' => 'lww', 'nullable' => true],
                'note' => ['strategy' => 'lww', 'nullable' => true],
                '_delete_wins' => true,
                '_create_required' => ['transaction_id'],
            ],
            // Leg table for split transactions — `_create_required` must stay a
            // subset of the migration's actual NOT-NULL-without-default columns
            // (each name must match a real column). TransactionSplitsRegistryColumnsTest
            // asserts this invariant.
            'transaction_splits' => [
                'category_id' => ['strategy' => 'lww', 'nullable' => false],
                'settled_amount_minor' => ['strategy' => 'lww', 'nullable' => false],
                'note' => ['strategy' => 'lww', 'nullable' => true],
                '_delete_wins' => true,
                '_create_required' => ['transaction_id', 'category_id', 'settled_amount_minor', 'settled_currency'],
            ],
            // One mutable snapshot row per (user_id, category_id,
            // period_start) — every `_create_required` name must match a real
            // NOT-NULL-without-default column on the migration.
            // EnvelopeAssignmentsRegistryColumnsTest guards this invariant.
            'envelope_assignments' => [
                'assigned_minor' => ['strategy' => 'lww', 'nullable' => false],
                '_delete_wins' => true,
                '_create_required' => ['user_id', 'category_id', 'period_start', 'assigned_minor', 'currency'],
            ],
            // Append-only paired-row ledger (mirrors pot_movements) — no
            // LWW-mutable field, only create + delete (undo hard-deletes both
            // paired rows).
            'envelope_moves' => [
                '_delete_wins' => true,
                '_create_required' => ['category_id', 'counterpart_category_id', 'period_start', 'amount_minor', 'currency', 'kind'],
            ],
            // One row per (user_id, category_id) holding the overspend-mode
            // toggle. The per-envelope notify threshold lives on this LIVE
            // table (not the write-dead `category_budgets`); nullable with no
            // DB default (null = "use the default"), so it stays OUT of `_create_required`.
            'envelope_settings' => [
                'overspend_mode' => ['strategy' => 'lww', 'nullable' => false],
                'threshold_percent' => ['strategy' => 'lww', 'nullable' => true],
                '_delete_wins' => true,
                '_create_required' => ['user_id', 'category_id', 'overspend_mode'],
            ],
            // Register both persistent Migration tables so a second device
            // cannot silently diverge and double-import; the six per-run
            // scratch staging tables and their parent run table are
            // deliberately NOT registered here.
            'migration_source_map' => [
                'beatrax_id' => ['strategy' => 'lww', 'nullable' => false],
                'natural_key' => ['strategy' => 'lww', 'nullable' => true],
                '_delete_wins' => true,
                '_create_required' => ['source_product', 'source_entity_type', 'beatrax_entity_type', 'beatrax_id'],
            ],
            // Sibling persistent table: the 3-way-merge baseline leg for
            // migration_source_map.
            'migration_import_baseline' => [
                'baseline_value' => ['strategy' => 'lww', 'nullable' => false],
                'imported_at' => ['strategy' => 'lww', 'nullable' => false],
                '_delete_wins' => true,
                '_create_required' => ['migration_source_map_id', 'field_name', 'baseline_value', 'imported_at'],
            ],
            // `pinned` carries a DB-level default(false) so it is
            // nullable:false in the strategy map below but MUST NOT appear in
            // `_create_required` — the same trap that bit
            // envelope_settings.overspend_mode above.
            'saved_reports' => [
                'name' => ['strategy' => 'lww', 'nullable' => false],
                'definition' => ['strategy' => 'lww', 'nullable' => false],
                'pinned' => ['strategy' => 'lww', 'nullable' => false],
                'pin_order' => ['strategy' => 'lww', 'nullable' => true],
                '_delete_wins' => true,
                '_create_required' => ['name', 'definition'],
            ],
            // `state` is deliberately absent — it is locally derived, never
            // synced. `id` IS in `_create_required`: notifications.id is a
            // non-autoincrement sha256 string PK (see @link), so CREATE_ROW
            // must carry it explicitly or `insertOrIgnore` drops the row.
            'notifications' => [
                'read_at' => ['strategy' => 'lww', 'nullable' => true],
                'dismissed_at' => ['strategy' => 'lww', 'nullable' => true],
                '_delete_wins' => true,
                // `state` has a DB default('open') so it is deliberately
                // excluded here (same pattern as saved_reports.pinned above).
                '_create_required' => ['id', 'user_id', 'title', 'body', 'trigger_type'],
            ],
            // Per-(user, device) notification preference row. These rows SYNC
            // so the other-devices settings panel can read them, but a device
            // only ever OBEYS its own row — that policy lives in
            // `SuppressionEvaluator`, not here.
            'notification_preferences' => [
                'reminders_enabled' => ['strategy' => 'lww', 'nullable' => false],
                'budget_nudges_enabled' => ['strategy' => 'lww', 'nullable' => false],
                'digest_cadence' => ['strategy' => 'lww', 'nullable' => false],
                'savings_prompts_enabled' => ['strategy' => 'lww', 'nullable' => false],
                'reminder_lead_days' => ['strategy' => 'lww', 'nullable' => false],
                'quiet_hours_enabled' => ['strategy' => 'lww', 'nullable' => false],
                'quiet_hours_from' => ['strategy' => 'lww', 'nullable' => true],
                'quiet_hours_to' => ['strategy' => 'lww', 'nullable' => true],
                'hide_details' => ['strategy' => 'lww', 'nullable' => false],
                '_delete_wins' => true,
                '_create_required' => ['user_id', 'device_id'],
            ],
        ];
    }

    // The replayer's table allow-list gate uses this to quarantine any op
    // whose table isn't registered — a compromised peer must not be able to
    // direct a SET/DELETE/CREATE op at an arbitrary wire-supplied table name
    // that was never meant to be op-log-replayable.
    public function isRegistered(string $table): bool
    {
        return array_key_exists($table, $this->rules());
    }

    /**
     * @return list<string>
     */
    public function registeredTables(): array
    {
        return array_keys($this->rules());
    }

    public function strategyFor(string $table, string $field): string
    {
        $tableRules = $this->rules()[$table] ?? null;

        if ($tableRules === null) {
            return 'lww';
        }

        /** @var array{strategy?: string}|null $fieldConfig */
        $fieldConfig = $tableRules[$field] ?? null;

        if (! is_array($fieldConfig)) {
            return 'lww';
        }

        return is_string($fieldConfig['strategy'] ?? null) ? $fieldConfig['strategy'] : 'lww';
    }

    /**
     * @return list<string>
     */
    public function requiredCreateColumns(string $table): array
    {
        $tableRules = $this->rules()[$table] ?? null;

        if ($tableRules === null) {
            return [];
        }

        /** @var list<string> $required */
        $required = $tableRules['_create_required'] ?? [];

        return $required;
    }

    public function deleteWins(string $table): bool
    {
        $tableRules = $this->rules()[$table] ?? null;

        if ($tableRules === null) {
            return true;
        }

        return (bool) ($tableRules['_delete_wins'] ?? true);
    }
}
