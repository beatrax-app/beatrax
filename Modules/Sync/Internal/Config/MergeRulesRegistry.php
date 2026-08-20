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

        return $this->rules = array_merge(
            $this->transactionAndMerchantRules(),
            $this->categorizationRules(),
            $this->financialEntityRules(),
            $this->taxAndSplitRules(),
            $this->envelopeRules(),
            $this->migrationRules(),
            $this->reportingAndNotificationRules(),
            $this->recurringAndChainRules(),
            $this->alertRules(),
            $this->preferenceAndScenarioRules(),
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function transactionAndMerchantRules(): array
    {
        return [
            // BEFORE transactions, which carry a NOT NULL import_run_id FK to
            // it. Left uncovered, every synced transaction referenced a run
            // the peer had never heard of and the insert failed the foreign
            // key, aborting the entire catch-up.
            'import_runs' => [
                'status' => ['strategy' => 'lww', 'nullable' => false],
                'confirmed_at' => ['strategy' => 'lww', 'nullable' => true],
                'inserted_count' => ['strategy' => 'lww', 'nullable' => false],
                'duplicate_count' => ['strategy' => 'lww', 'nullable' => false],
                'error_count' => ['strategy' => 'lww', 'nullable' => false],
                'enriched_count' => ['strategy' => 'lww', 'nullable' => false],
                '_delete_wins' => true,
                // NOT NULL without a default; the counters and status all
                // carry defaults and stay out of the required set.
                '_create_required' => ['source_format', 'raw_file_path', 'sha256', 'uploaded_at'],
            ],
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
            // BEFORE merchant_memories, which holds a NOT NULL merchant_id FK
            // to it. Uncovered, a synced memory pointed at a merchant the peer
            // never received and the insert failed its foreign key.
            'merchants' => [
                'name' => ['strategy' => 'lww', 'nullable' => false],
                'normalized_name' => ['strategy' => 'lww', 'nullable' => false],
                'default_category_id' => ['strategy' => 'lww', 'nullable' => true],
                '_delete_wins' => true,
                '_create_required' => ['name', 'normalized_name'],
            ],
            // `merchant_id` is the NOT-NULL-without-default identity FK (user_id
            // is nullable per the multi-user convention; occurrence_count has a
            // DB default(0) so it stays OUT of `_create_required`).
            'merchant_memories' => [
                'occurrence_count' => ['strategy' => 'g_counter', 'nullable' => false],
                'last_seen_at' => ['strategy' => 'lww', 'nullable' => true],
                '_delete_wins' => true,
                '_create_required' => ['merchant_id'],
            ],
            // `pattern` is the immutable first-seen raw description and the
            // per-user identity column (UNIQUE user_id, pattern) — the only
            // NOT-NULL-without-default string besides the nullable user_id FK.
            'merchant_aliases' => [
                'generalized_pattern' => ['strategy' => 'lww', 'nullable' => true],
                'friendly_name' => ['strategy' => 'lww', 'nullable' => true],
                'merged_from' => ['strategy' => 'or_set', 'nullable' => true],
                '_delete_wins' => true,
                '_create_required' => ['pattern'],
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function categorizationRules(): array
    {
        return [
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
                // priority, combinator, active and hits_count all carry DB
                // defaults and user_id is nullable, so categorization_rules has
                // no NOT-NULL-without-default column and a CreateRow inserts on
                // defaults alone. RuleSchemaMigrationTest asserts the emptiness.
                '_create_required' => [],
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
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function financialEntityRules(): array
    {
        return [
            // `website`/`logo_url` are folded into the `metadata` JSON column —
            // no dedicated columns exist. `slug` (UNIQUE user_id, slug) is the
            // identity string; both slug and type are NOT-NULL-without-default.
            'counterparties' => [
                'display_name' => ['strategy' => 'lww', 'nullable' => false],
                'type' => ['strategy' => 'lww', 'nullable' => false],
                '_delete_wins' => true,
                '_create_required' => ['slug', 'type'],
            ],
            // Pot targets live on the linked goal, not on this table — there is
            // no `target_amount_minor` column here. `name` and `currency` are
            // the NOT-NULL-without-default columns.
            'pots' => [
                'name' => ['strategy' => 'lww', 'nullable' => false],
                'currency' => ['strategy' => 'lww', 'nullable' => false],
                '_delete_wins' => true,
                '_create_required' => ['name', 'currency'],
            ],
            // AFTER pots, whose id it names. Append-only allocation ledger
            // mirroring envelope_moves: a movement is inserted and never
            // edited, so there is no LWW-mutable field, only create and
            // delete. Uncovered, every pot balance summed to zero on a peer.
            'pot_movements' => [
                '_delete_wins' => true,
                // `user_id` is nullable per the multi-user convention and
                // `counterpart_pot_id` is null for fund/withdraw, so neither
                // belongs in the required set.
                '_create_required' => ['pot_id', 'amount_minor', 'currency', 'kind'],
            ],
            // Money column is `target_minor` (not `target_amount_minor`) and the
            // deadline is `target_date`. `target_currency` defaults to 'EUR' in
            // the DB, leaving `name` and `target_minor` as the only
            // NOT-NULL-without-default columns.
            'goals' => [
                'name' => ['strategy' => 'lww', 'nullable' => false],
                'target_minor' => ['strategy' => 'lww', 'nullable' => false],
                'target_currency' => ['strategy' => 'lww', 'nullable' => false],
                'target_date' => ['strategy' => 'lww', 'nullable' => false],
                '_delete_wins' => true,
                '_create_required' => ['name', 'target_minor'],
            ],
            // Append-only attribution pivot, mirroring envelope_moves: an
            // attribution exists or it does not, so there is no LWW-mutable
            // field and no strategy key — only create and delete.
            'goal_contributions' => [
                '_delete_wins' => true,
                '_create_required' => ['goal_id', 'transaction_id'],
            ],
            // `default_currency` carries a DB default('EUR') so it MUST NOT
            // appear in `_create_required` (same trap as saved_reports.pinned /
            // envelope_settings.overspend_mode). name/kind/iban are the
            // NOT-NULL-without-default columns.
            'accounts' => [
                'name' => ['strategy' => 'lww', 'nullable' => false],
                'default_currency' => ['strategy' => 'lww', 'nullable' => false],
                '_delete_wins' => false,
                '_create_required' => ['name', 'kind', 'iban'],
            ],
            'categories' => [
                'name' => ['strategy' => 'lww', 'nullable' => false],
                'slug' => ['strategy' => 'lww', 'nullable' => false],
                'kind' => ['strategy' => 'lww', 'nullable' => false],
                '_delete_wins' => true,
                '_create_required' => ['name', 'slug', 'kind'],
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function taxAndSplitRules(): array
    {
        return [
            // BEFORE tax_transaction_tags, which references it. These are the
            // user's own deduction categories, not a seeded reference list, so
            // a peer only has them if they are synced.
            'tax_deduction_categories' => [
                'name' => ['strategy' => 'lww', 'nullable' => false],
                'short_name' => ['strategy' => 'lww', 'nullable' => true],
                'hint' => ['strategy' => 'lww', 'nullable' => true],
                'corpus_key' => ['strategy' => 'lww', 'nullable' => true],
                'country_code' => ['strategy' => 'lww', 'nullable' => true],
                'status' => ['strategy' => 'lww', 'nullable' => false],
                'sort_order' => ['strategy' => 'lww', 'nullable' => false],
                '_delete_wins' => true,
                // status and sort_order both carry DB defaults, so neither
                // belongs in the required set.
                '_create_required' => ['name'],
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
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function envelopeRules(): array
    {
        return [
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
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function migrationRules(): array
    {
        return [
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
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function reportingAndNotificationRules(): array
    {
        return [
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
            // synced. `id` stays listed for the record that this PK is a
            // sha256 string rather than an autoincrement, but the applier
            // seeds it from the op's own pk and never needs it as a field.
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

    /**
     * @return array<string, array<string, mixed>>
     */
    private function recurringAndChainRules(): array
    {
        return [
            // AFTER transactions — both endpoints are foreign keys into it —
            // and BEFORE recurring_series, which names a funding link. Only
            // `state` and `resolver` move after insert (confirm/reject and the
            // auto-promote sweep); the resolvers insert rather than rewrite.
            'chain_links' => [
                'state' => ['strategy' => 'lww', 'nullable' => false],
                'resolver' => ['strategy' => 'lww', 'nullable' => false],
                'to_transaction_id' => ['strategy' => 'lww', 'nullable' => true],
                'confidence' => ['strategy' => 'lww', 'nullable' => false],
                'evidence' => ['strategy' => 'lww', 'nullable' => false],
                '_delete_wins' => true,
                // `to_transaction_id` is deliberately absent: it is NULL by
                // design on hint and exceeded-tolerance candidate rows, which
                // a trigger pair on the table enforces.
                '_create_required' => ['from_transaction_id', 'kind', 'state', 'confidence', 'resolver', 'evidence'],
            ],
            // The detector rewrites the latest_*/next_expected_* metrics on
            // every sweep and the user owns state, name and thresholds — both
            // are per-field last-writer-wins. `cluster_key`/`direction` are the
            // identity half of the UNIQUE and are never rewritten.
            'recurring_series' => [
                'state' => ['strategy' => 'lww', 'nullable' => false],
                'cadence' => ['strategy' => 'lww', 'nullable' => false],
                'detected_name' => ['strategy' => 'lww', 'nullable' => false],
                'display_name_override' => ['strategy' => 'lww', 'nullable' => true],
                'latest_amount_minor' => ['strategy' => 'lww', 'nullable' => false],
                'latest_currency' => ['strategy' => 'lww', 'nullable' => false],
                'latest_fx_rate_used' => ['strategy' => 'lww', 'nullable' => true],
                'monthly_equivalent_minor' => ['strategy' => 'lww', 'nullable' => true],
                'variance_tolerance_percent' => ['strategy' => 'lww', 'nullable' => false],
                'drift_threshold_percent' => ['strategy' => 'lww', 'nullable' => true],
                'latest_funding_chain_link_id' => ['strategy' => 'lww', 'nullable' => true],
                'snoozed_until' => ['strategy' => 'lww', 'nullable' => true],
                'next_expected_at' => ['strategy' => 'lww', 'nullable' => true],
                'next_expected_confidence_low' => ['strategy' => 'lww', 'nullable' => false],
                'cluster_counterparty_key' => ['strategy' => 'lww', 'nullable' => true],
                '_delete_wins' => true,
                // state, cadence, variance_tolerance_percent and
                // next_expected_confidence_low all carry DB defaults, so none
                // of them belongs in the required set.
                '_create_required' => ['direction', 'detected_name', 'latest_amount_minor', 'latest_currency', 'cluster_key'],
            ],
            // AFTER recurring_series. Append-only detector output, written with
            // insertOrIgnore and never updated, so it declares no mergeable
            // field: the (series, transaction) UNIQUE is the same idempotency
            // seam on the peer that it is on the device that detected it.
            'recurring_series_occurrences' => [
                '_delete_wins' => true,
                '_create_required' => [
                    'recurring_series_id',
                    'transaction_id',
                    'observed_at',
                    'observed_amount_minor',
                    'observed_currency',
                ],
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function alertRules(): array
    {
        return [
            // AFTER recurring_series and its occurrences, both of which it
            // names. The alert body is written once by the detector and frozen
            // — only the review columns move afterwards, each through the
            // state machine, so whoever acted on the alert last wins.
            'drift_alerts' => [
                'state' => ['strategy' => 'lww', 'nullable' => false],
                'snoozed_until' => ['strategy' => 'lww', 'nullable' => true],
                'actioned_at' => ['strategy' => 'lww', 'nullable' => true],
                '_delete_wins' => true,
                // `state` carries a DB default('open') so it stays out; every
                // other column here is NOT NULL without one, including the
                // frozen threshold pair that records what the alert was judged on.
                '_create_required' => [
                    'recurring_series_id',
                    'direction',
                    'baseline_amount_minor',
                    'latest_amount_minor',
                    'currency',
                    'delta_minor',
                    'annualized_impact_minor',
                    'threshold_percent_used',
                    'threshold_source',
                    'latest_occurrence_id',
                    'detected_at',
                ],
            ],
            // Same review shape as drift_alerts but keyed per transaction.
            // `dismissed_as` rides along with the dismissal transition, so it
            // merges the same way the state it explains does.
            'anomaly_alerts' => [
                'state' => ['strategy' => 'lww', 'nullable' => false],
                'dismissed_as' => ['strategy' => 'lww', 'nullable' => true],
                'snoozed_until' => ['strategy' => 'lww', 'nullable' => true],
                'actioned_at' => ['strategy' => 'lww', 'nullable' => true],
                '_delete_wins' => true,
                // `state` has a DB default('open'); the amount baselines and
                // the currency are all nullable because a first-time-merchant
                // flag has no prior amount to compare against.
                '_create_required' => ['transaction_id', 'direction', 'reasons'],
            ],
            // Acknowledging stamps `acknowledged_at` rather than deleting, so
            // that is the one column that moves. Rows with a NULL user_id are
            // system-wide and belong to no one: the backfill scopes on user_id
            // and never captures them, which is the intent — they are local.
            'system_alerts' => [
                'acknowledged_at' => ['strategy' => 'lww', 'nullable' => true],
                '_delete_wins' => true,
                // `created_at` defaults to CURRENT_TIMESTAMP and there is no
                // `updated_at` column at all on this table.
                '_create_required' => ['kind', 'severity', 'message'],
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function preferenceAndScenarioRules(): array
    {
        return [
            // One row per user (UNIQUE user_id), so every column is a plain
            // per-user setting and last-writer-wins is the whole story. A
            // device does NOT get its own view mode — that is the cost of the
            // row being per-user rather than per-device.
            'user_preferences' => [
                'counterparty_index_view' => ['strategy' => 'lww', 'nullable' => false],
                'reports_index_view' => ['strategy' => 'lww', 'nullable' => false],
                // A grow-only list of skipped versions, but stored as a plain
                // JSON array of strings rather than the {v, tag} shape or_set
                // requires, so it merges as lww: two devices skipping
                // different versions concurrently keeps only the later one.
                'skipped_update_versions' => ['strategy' => 'lww', 'nullable' => false],
                'calendar_entries_accounts' => ['strategy' => 'lww', 'nullable' => true],
                'calendar_balance_accounts' => ['strategy' => 'lww', 'nullable' => true],
                '_delete_wins' => true,
                // Both *_index_view columns and skipped_update_versions carry
                // DB defaults, leaving user_id as the only required column.
                '_create_required' => ['user_id'],
            ],
            // Named what-if containers the user writes by hand; both columns
            // are theirs to rename, so both are last-writer-wins. `user_id` is
            // NOT NULL here (unlike the nullable multi-user convention
            // elsewhere), which is why it is a required create column.
            'forecast_scenarios' => [
                'name' => ['strategy' => 'lww', 'nullable' => false],
                'description' => ['strategy' => 'lww', 'nullable' => true],
                '_delete_wins' => true,
                '_create_required' => ['user_id', 'name'],
            ],
            // AFTER forecast_scenarios, whose id it names. This is the
            // scenario's CONTENT: covered only by the container before, a
            // synced scenario arrived as an empty named box. `kind` is frozen
            // after insert — changing one is a remove plus a re-add.
            'forecast_scenario_mutations' => [
                'payload' => ['strategy' => 'lww', 'nullable' => false],
                'target_series_id' => ['strategy' => 'lww', 'nullable' => true],
                '_delete_wins' => true,
                // `id` is seeded from the op's own pk and must never be
                // listed; `target_series_id` is null for the two kinds that
                // name no series, and the timestamps are nullable.
                '_create_required' => ['user_id', 'forecast_scenario_id', 'kind', 'payload'],
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
