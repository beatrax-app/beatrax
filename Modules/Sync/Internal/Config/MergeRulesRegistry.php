<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Config;

/**
 * Config-driven per-table, per-field CRDT merge strategy registry.
 *
 * Maps (table → field → strategy config) for all user-editable tables.
 * Adding a new table for capture = one entry here + hand-wired emission at
 * the edit site. No engine changes required (D-05).
 *
 * Strategy keys: 'lww' | 'g_counter' | 'or_set'
 * Unknown table/field defaults to 'lww'.
 *
 * Per-table special keys:
 *   _delete_wins      bool  — tombstone wins on equal-HLC tie (default true)
 *   _create_required  list  — NOT NULL columns required in CreateRow ops
 *
 * Source: 10-FINDINGS.md per-table conflict-resolution rules table.
 * Schema verified against database/schema/sqlite-schema.sql.
 */
final class MergeRulesRegistry
{
    /**
     * Memoized rules map (WR-09). strategyFor()/requiredCreateColumns() are
     * called once per (pk, field) in the replayer's CREATE and SET loops, so
     * re-allocating the full literal array on every call wastes work in a
     * merge of thousands of ops. Built once, then reused.
     *
     * @var array<string, array<string, mixed>>|null
     */
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
            // 13.4-01 (Req 5/D-01): categorization_rules is now the PARENT of
            // the multi-condition/multi-action rules engine; field/match/
            // value/category_id moved to the new rule_conditions/rule_actions
            // child tables below. NOTE: rule-authoring sync stays
            // intentionally out of scope for 13.4 — Create/Update/
            // DeleteCategorizationRule dispatch no TransactionMutated-style
            // event today, so this config is forward-prepared, not yet
            // an active sync surface (RESEARCH.md Pitfall 5 / Assumption A3).
            'categorization_rules' => [
                'priority' => ['strategy' => 'lww', 'nullable' => false],
                'combinator' => ['strategy' => 'lww', 'nullable' => false],
                'active' => ['strategy' => 'lww', 'nullable' => false],
                'notes' => ['strategy' => 'lww', 'nullable' => true],
                'hits_count' => ['strategy' => 'lww', 'nullable' => false],
                '_delete_wins' => true,
                '_create_required' => ['priority', 'combinator', 'active', 'hits_count'],
            ],
            // 13.4-01 (Req 5/D-02): condition child table. Cross-checked
            // against
            // Modules/Categorization/Database/Migrations/2026_07_06_000002_create_rule_conditions_table.php.
            'rule_conditions' => [
                'field' => ['strategy' => 'lww', 'nullable' => false],
                'op' => ['strategy' => 'lww', 'nullable' => false],
                'value_type' => ['strategy' => 'lww', 'nullable' => false],
                'value' => ['strategy' => 'lww', 'nullable' => false],
                'value2' => ['strategy' => 'lww', 'nullable' => true],
                '_delete_wins' => true,
                '_create_required' => ['rule_id', 'field', 'op', 'value_type', 'value'],
            ],
            // 13.4-01 (Req 5/D-03): action child table. Cross-checked against
            // Modules/Categorization/Database/Migrations/2026_07_06_000003_create_rule_actions_table.php.
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
            'category_budgets' => [
                'monthly_limit_minor' => ['strategy' => 'lww', 'nullable' => false],
                'currency' => ['strategy' => 'lww', 'nullable' => false],
                '_delete_wins' => true,
                '_create_required' => ['category_id', 'monthly_limit_minor', 'currency'],
            ],
            // IN-01 (Phase 13.1): corrected the phantom `tag` column (no such
            // column exists) to the real editable columns, and added
            // `transaction_split_id` so a leg-scoped tax tag replays on a peer
            // WITH its leg scope — without it, a per-leg deduction would
            // collapse into a whole-transaction tag and corrupt exported tax
            // amounts. Columns cross-checked against
            // Modules/Tax/Database/Migrations/2026_06_12_000002_create_tax_transaction_tags_table.php
            // and 2026_07_04_000002_add_transaction_split_id_to_tax_transaction_tags.php.
            // `transaction_id` is the only NOT-NULL-without-default column.
            'tax_transaction_tags' => [
                'transaction_split_id' => ['strategy' => 'lww', 'nullable' => true],
                'deduction_category_id' => ['strategy' => 'lww', 'nullable' => true],
                'tax_year_override' => ['strategy' => 'lww', 'nullable' => true],
                'note' => ['strategy' => 'lww', 'nullable' => true],
                '_delete_wins' => true,
                '_create_required' => ['transaction_id'],
            ],
            // 13.1-03 (Req 10 / D-12): leg table for split transactions.
            // Every string below is cross-checked against
            // Modules/Ledger/Database/Migrations/2026_07_04_000001_create_transaction_splits_table.php
            // — do NOT replicate the category_budgets monthly_limit_minor/budget_minor
            // typo above; TransactionSplitsRegistryColumnsTest asserts this stays a
            // subset of the migration's actual NOT-NULL-without-default columns.
            'transaction_splits' => [
                'category_id' => ['strategy' => 'lww', 'nullable' => false],
                'settled_amount_minor' => ['strategy' => 'lww', 'nullable' => false],
                'note' => ['strategy' => 'lww', 'nullable' => true],
                '_delete_wins' => true,
                '_create_required' => ['transaction_id', 'category_id', 'settled_amount_minor', 'settled_currency'],
            ],
            // 13.2-05 (Req 11 / D-25): one mutable snapshot row per
            // (user_id, category_id, period_start). Every string below is
            // cross-checked against
            // Modules/Budgets/Database/Migrations/2026_07_05_000001_create_envelope_assignments_table.php
            // — do NOT replicate the category_budgets monthly_limit_minor/
            // budget_minor typo above; EnvelopeAssignmentsRegistryColumnsTest
            // asserts this stays a subset of the migration's actual
            // NOT-NULL-without-default columns.
            'envelope_assignments' => [
                'assigned_minor' => ['strategy' => 'lww', 'nullable' => false],
                '_delete_wins' => true,
                '_create_required' => ['user_id', 'category_id', 'period_start', 'assigned_minor', 'currency'],
            ],
            // 13.2-05 (Req 11 / D-25): append-only paired-row ledger (mirrors
            // pot_movements) — no LWW-mutable field, only create + delete
            // (undo hard-deletes both paired rows). Cross-checked against
            // Modules/Budgets/Database/Migrations/2026_07_05_000002_create_envelope_moves_table.php.
            'envelope_moves' => [
                '_delete_wins' => true,
                '_create_required' => ['category_id', 'counterpart_category_id', 'period_start', 'amount_minor', 'currency', 'kind'],
            ],
            // 13.2-05 (Req 11 / D-25): one row per (user_id, category_id)
            // holding the overspend-mode toggle. Cross-checked against
            // Modules/Budgets/Database/Migrations/2026_07_05_000003_create_envelope_settings_table.php.
            // 18-04 (D-20 redirect, see class docblock reference in
            // 18-04-PLAN.md <planner_decisions>): the D-20 per-envelope
            // notify threshold landed on this LIVE table (not the write-dead
            // `category_budgets`) via
            // Modules/Budgets/Database/Migrations/2026_07_17_000003_add_threshold_percent_to_envelope_settings.php.
            // Nullable with no DB default (null = "use the 90% D-20
            // default"), so it stays OUT of `_create_required` below.
            'envelope_settings' => [
                'overspend_mode' => ['strategy' => 'lww', 'nullable' => false],
                'threshold_percent' => ['strategy' => 'lww', 'nullable' => true],
                '_delete_wins' => true,
                '_create_required' => ['user_id', 'category_id', 'overspend_mode'],
            ],
            // 13.5-01 (Req 9/10, Open Question 5 resolved: register both
            // persistent Migration tables so a second device cannot silently
            // diverge and double-import; the six per-run scratch staging
            // tables and their parent run table are deliberately NOT
            // registered here). Cross-checked against
            // Modules/Migration/Database/Migrations/2026_07_06_000003_create_migration_source_map_table.php.
            'migration_source_map' => [
                'beatrax_id' => ['strategy' => 'lww', 'nullable' => false],
                'natural_key' => ['strategy' => 'lww', 'nullable' => true],
                '_delete_wins' => true,
                '_create_required' => ['source_product', 'source_entity_type', 'beatrax_entity_type', 'beatrax_id'],
            ],
            // 13.5-01: sibling persistent table, the 3-way-merge baseline leg
            // (D-11). Cross-checked against
            // Modules/Migration/Database/Migrations/2026_07_06_000004_create_migration_import_baseline_table.php.
            'migration_import_baseline' => [
                'baseline_value' => ['strategy' => 'lww', 'nullable' => false],
                'imported_at' => ['strategy' => 'lww', 'nullable' => false],
                '_delete_wins' => true,
                '_create_required' => ['migration_source_map_id', 'field_name', 'baseline_value', 'imported_at'],
            ],
            // 999.6-01 (Req 9/10, D-02): user's saved report definitions + dashboard
            // pin state. Cross-checked against
            // Modules/Reports/Database/Migrations/2026_07_07_000001_create_saved_reports_table.php
            // — `pinned` carries a DB-level default(false) so it is nullable:false in
            // the strategy map below but MUST NOT appear in _create_required
            // (Pitfall 5 — the same trap that bit envelope_settings.overspend_mode).
            'saved_reports' => [
                'name' => ['strategy' => 'lww', 'nullable' => false],
                'definition' => ['strategy' => 'lww', 'nullable' => false],
                'pinned' => ['strategy' => 'lww', 'nullable' => false],
                'pin_order' => ['strategy' => 'lww', 'nullable' => true],
                '_delete_wins' => true,
                '_create_required' => ['name', 'definition'],
            ],
            // 18-04 (Req 11/D-05/D-09): notifications inbox. `state` is
            // DELIBERATELY ABSENT — it is locally derived by
            // `NotificationStateMachine`, never synced (18-01
            // <planner_decisions>). `read_at`/`dismissed_at` are plain
            // nullable LWW latches; convergence falls out of the
            // deterministic sha256 string PK + existing LWW merge, no new
            // OpType. Cross-checked against
            // Modules/Notifications/Database/Migrations/2026_07_17_010001_create_notifications_table.php.
            'notifications' => [
                'read_at' => ['strategy' => 'lww', 'nullable' => true],
                'dismissed_at' => ['strategy' => 'lww', 'nullable' => true],
                '_delete_wins' => true,
                // `state` has a DB default('open') so it is deliberately
                // excluded here (same pattern as saved_reports.pinned above).
                '_create_required' => ['user_id', 'title', 'body', 'trigger_type'],
            ],
            // 18-04 (D-07/D-34/D-35): per-(user, device) notification
            // preference row. These rows SYNC so the other-devices settings
            // panel can read them, but a device only ever OBEYS its own row
            // — that policy lives in `SuppressionEvaluator`, not here.
            // Cross-checked against
            // Modules/Notifications/Database/Migrations/2026_07_17_010002_create_notification_preferences_table.php.
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

    /**
     * Whether the given table is a registered sync surface (i.e. has an
     * entry in rules()). The replayer's table allow-list gate uses this to
     * quarantine ('unknown_table') any op whose table is not one this
     * registry explicitly knows how to merge — a compromised peer must not
     * be able to direct a SET/DELETE/CREATE op at an arbitrary wire-supplied
     * table name (e.g. device_registry) that was never meant to be
     * op-log-replayable.
     */
    public function isRegistered(string $table): bool
    {
        return array_key_exists($table, $this->rules());
    }

    /**
     * All table names registered for op-log replay.
     *
     * @return list<string>
     */
    public function registeredTables(): array
    {
        return array_keys($this->rules());
    }

    /**
     * Returns the strategy key for a given (table, field) pair.
     * Defaults to 'lww' for unknown tables or fields.
     */
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
     * Returns the list of required columns for a CreateRow op on the given table.
     *
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

    /**
     * Whether tombstone delete-wins semantics apply for the given table.
     * Defaults to true (delete wins on equal-HLC tie).
     */
    public function deleteWins(string $table): bool
    {
        $tableRules = $this->rules()[$table] ?? null;

        if ($tableRules === null) {
            return true;
        }

        return (bool) ($tableRules['_delete_wins'] ?? true);
    }
}
