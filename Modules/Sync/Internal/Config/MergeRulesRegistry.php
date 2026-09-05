<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Config;

use Modules\Sync\Internal\Merge\MergeStrategy;

/**
 * @link ../../../../.docs/features/sync/merge-registry-authoring.md
 */
final class MergeRulesRegistry
{
    // `users` is the only covered table whose row mixes the reader's settings
    // with columns belonging to one device: a peer that received `password` or
    // `theme` would take over the login and the appearance. Listed here so the
    // capture filter and the column-coverage guard read one answer.
    /** @var array<string, list<string>> */
    private const array DEVICE_LOCAL_COLUMNS = [
        'users' => [
            'id',
            'username',
            'password',
            'remember_token',
            'is_developer',
            'force_password_change_at_next_login',
            'theme',
            'locale',
            'close_behavior',
            // The update check is a property of an installed binary, not of the
            // reader: a phone is updated by its store and all three listeners
            // already refuse there, so a phone's answer arriving on a desktop
            // would switch off that desktop's only binary-integrity signal.
            'auto_update_check_enabled',
            'auto_import_drop_folder',
            'anomaly_backfilled_at',
            'created_at',
            'updated_at',
        ],
    ];

    // Neither synced nor device-local: every joining device is asked for its
    // own answer during onboarding, because the answer decides which corpus
    // that device seeds.
    /** @var array<string, list<string>> */
    private const array ASKED_OF_EVERY_JOINER = [
        'users' => ['country_code'],
    ];

    // Memoized: strategyFor()/requiredCreateColumns() run once per (pk, field)
    // in the replayer's CREATE and SET loops, over thousands of ops.
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
                'status' => ['nullable' => false],
                'confirmed_at' => ['nullable' => true],
                'inserted_count' => ['nullable' => false],
                'duplicate_count' => ['nullable' => false],
                'error_count' => ['nullable' => false],
                'enriched_count' => ['nullable' => false],
                '_delete_wins' => true,
                // NOT NULL without a default; the counters and status all
                // carry defaults and stay out of the required set.
                '_create_required' => ['source_format', 'raw_file_path', 'sha256', 'uploaded_at'],
            ],
            'transactions' => [
                'category_id' => ['nullable' => true],
                'note' => ['nullable' => true],
                'counterparty_id' => ['nullable' => true],
                'type' => ['nullable' => false],
                'status' => ['nullable' => false],
                '_delete_wins' => true,
                // NOT NULL columns without defaults in transactions (status has default 'cleared',
                // payment_type has default 'unknown' — omitted from required list).
                '_create_required' => [
                    'type',
                    'account_id',
                    'posted_at',
                    'booked_at',
                    'value_date',
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
                'name' => ['nullable' => false],
                'normalized_name' => ['nullable' => false],
                'default_category_id' => ['nullable' => true],
                '_delete_wins' => true,
                '_create_required' => ['name', 'normalized_name'],
            ],
            // `merchant_id` and `category_id` are the NOT-NULL-without-default
            // identity FKs (user_id is nullable per the multi-user convention;
            // occurrence_count has a DB default(0) so it stays OUT).
            'merchant_memories' => [
                'occurrence_count' => ['strategy' => MergeStrategy::GCounter->value, 'nullable' => false],
                'last_seen_at' => ['nullable' => true],
                '_delete_wins' => true,
                '_create_required' => ['merchant_id', 'category_id'],
            ],
            // `pattern` is the immutable first-seen raw description and the
            // per-user identity column (UNIQUE user_id, pattern) — but not the
            // only NOT-NULL-without-default one, and a create missing
            // generalized_pattern or friendly_name wrote no row at all.
            'merchant_aliases' => [
                'generalized_pattern' => ['nullable' => true],
                'friendly_name' => ['nullable' => true],
                'merged_from' => ['strategy' => MergeStrategy::OrSet->value, 'nullable' => true],
                '_delete_wins' => true,
                '_create_required' => ['pattern', 'generalized_pattern', 'friendly_name'],
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
                'priority' => ['nullable' => false],
                'combinator' => ['nullable' => false],
                'active' => ['nullable' => false],
                'notes' => ['nullable' => true],
                'hits_count' => ['nullable' => false],
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
                'field' => ['nullable' => false],
                'op' => ['nullable' => false],
                'value_type' => ['nullable' => false],
                'value' => ['nullable' => false],
                'value2' => ['nullable' => true],
                '_delete_wins' => true,
                '_create_required' => ['rule_id', 'field', 'op', 'value_type', 'value'],
            ],
            // Action child table; cross-checked against the rule_actions
            // migration in Modules/Categorization.
            'rule_actions' => [
                'position' => ['nullable' => false],
                'type' => ['nullable' => false],
                'payload' => ['nullable' => false],
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
            // The ignored flag and the subcategory are folded into the
            // `metadata` JSON column — no dedicated columns exist. `slug`
            // (UNIQUE user_id, slug) is the identity string; slug, type and
            // display_name are all NOT-NULL-without-default.
            'counterparties' => [
                'display_name' => ['nullable' => false],
                'type' => ['nullable' => false],
                '_delete_wins' => true,
                '_create_required' => ['slug', 'type', 'display_name'],
            ],
            // Pot targets live on the linked goal, not on this table — there is
            // no `target_amount_minor` column here. `account_id`, `name` and
            // `currency` are the NOT-NULL-without-default columns.
            'pots' => [
                'name' => ['nullable' => false],
                'currency' => ['nullable' => false],
                '_delete_wins' => true,
                '_create_required' => ['account_id', 'name', 'currency'],
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
            // the DB; name, target_minor, start_date and target_date are the
            // NOT-NULL-without-default columns.
            'goals' => [
                'name' => ['nullable' => false],
                'target_minor' => ['nullable' => false],
                'target_currency' => ['nullable' => false],
                'target_date' => ['nullable' => false],
                '_delete_wins' => true,
                '_create_required' => ['name', 'target_minor', 'start_date', 'target_date'],
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
            // envelope_settings.overspend_mode). name/slug/kind/iban are the
            // NOT-NULL-without-default columns.
            'accounts' => [
                'name' => ['nullable' => false],
                'default_currency' => ['nullable' => false],
                '_delete_wins' => false,
                '_create_required' => ['name', 'slug', 'kind', 'iban'],
            ],
            // `name_is_default` travels with `name`: a peer that took the
            // rename and not the flag would keep translating the slug over
            // the top of the user's own words. It carries a DB default, so it
            // stays out of `_create_required`.
            'categories' => [
                'name' => ['nullable' => false],
                'name_is_default' => ['nullable' => false],
                'slug' => ['nullable' => false],
                'kind' => ['nullable' => false],
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
                'name' => ['nullable' => false],
                'short_name' => ['nullable' => true],
                'hint' => ['nullable' => true],
                'corpus_key' => ['nullable' => true],
                'country_code' => ['nullable' => true],
                'name_is_default' => ['nullable' => false],
                'status' => ['nullable' => false],
                'sort_order' => ['nullable' => false],
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
                'transaction_split_id' => ['nullable' => true],
                'deduction_category_id' => ['nullable' => true],
                'tax_year_override' => ['nullable' => true],
                'note' => ['nullable' => true],
                '_delete_wins' => true,
                '_create_required' => ['transaction_id'],
            ],
            // Leg table for split transactions — `_create_required` must stay a
            // subset of the migration's actual NOT-NULL-without-default columns
            // (each name must match a real column). TransactionSplitsRegistryColumnsTest
            // asserts this invariant.
            'transaction_splits' => [
                'category_id' => ['nullable' => false],
                'settled_amount_minor' => ['nullable' => false],
                'note' => ['nullable' => true],
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
                'assigned_minor' => ['nullable' => false],
                // Mutable beside the amount, not only at create: re-typing the
                // figure the grid showed after a reporting-currency switch
                // rewrites the sign the row is denominated in.
                'currency' => ['nullable' => false],
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
            // toggle and the per-envelope notify threshold, which is nullable
            // with no DB default (null = "use the default") and so stays OUT
            // of `_create_required`.
            'envelope_settings' => [
                'overspend_mode' => ['nullable' => false],
                'threshold_percent' => ['nullable' => true],
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
                'beatrax_id' => ['nullable' => false],
                'natural_key' => ['nullable' => true],
                '_delete_wins' => true,
                '_create_required' => ['source_product', 'source_entity_type', 'beatrax_entity_type', 'beatrax_id'],
            ],
            // Sibling persistent table: the 3-way-merge baseline leg for
            // migration_source_map.
            'migration_import_baseline' => [
                'baseline_value' => ['nullable' => false],
                'imported_at' => ['nullable' => false],
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
                'name' => ['nullable' => false],
                'definition' => ['nullable' => false],
                'pinned' => ['nullable' => false],
                'pin_order' => ['nullable' => true],
                '_delete_wins' => true,
                '_create_required' => ['name', 'definition'],
            ],
            // `state` is deliberately absent — it is locally derived, never
            // synced. `id` stays listed for the record that this PK is a
            // sha256 string rather than an autoincrement, but the applier
            // seeds it from the op's own pk and never needs it as a field.
            'notifications' => [
                'read_at' => ['nullable' => true],
                'dismissed_at' => ['nullable' => true],
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
                'reminders_enabled' => ['nullable' => false],
                'budget_nudges_enabled' => ['nullable' => false],
                'digest_cadence' => ['nullable' => false],
                'savings_prompts_enabled' => ['nullable' => false],
                'reminder_lead_days' => ['nullable' => false],
                'quiet_hours_enabled' => ['nullable' => false],
                'quiet_hours_from' => ['nullable' => true],
                'quiet_hours_to' => ['nullable' => true],
                'hide_details' => ['nullable' => false],
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

            // The `id` is DERIVED, from the (user_id, from_transaction_id,
            // to_transaction_id, kind) tuple ChainLinkInsertHelper already
            // dedupes on. The table has no UNIQUE of its own, so that helper
            // was the only statement of what makes a link the same link.
            'chain_links' => [
                'state' => ['nullable' => false],
                'resolver' => ['nullable' => false],
                'to_transaction_id' => ['nullable' => true],
                'confidence' => ['nullable' => false],
                'evidence' => ['nullable' => false],
                '_delete_wins' => true,
                // `to_transaction_id` is deliberately absent: it is NULL by
                // design on hint and exceeded-tolerance candidate rows, which
                // a trigger pair on the table enforces.
                '_create_required' => ['from_transaction_id', 'kind', 'state', 'confidence', 'resolver', 'evidence'],
            ],
            // The detector rewrites the latest_*/next_expected_* metrics on
            // every sweep and the user owns state, name and thresholds — both
            // are per-field last-writer-wins.

            // The `id` is DERIVED, from (user_id, direction,
            // cluster_counterparty_key, latest_currency) — not the table's
            // UNIQUE, because SeriesRefresher rewrites `cluster_key` in place
            // whenever a cadence band moves.

            // `cluster_key` therefore travels as an ordinary mergeable column;
            // `direction` never moves and carries no rule.
            'recurring_series' => [
                'state' => ['nullable' => false],
                'cadence' => ['nullable' => false],
                'cluster_key' => ['nullable' => false],
                'detected_name' => ['nullable' => false],
                'display_name_override' => ['nullable' => true],
                'latest_amount_minor' => ['nullable' => false],
                'latest_currency' => ['nullable' => false],
                'monthly_equivalent_minor' => ['nullable' => true],
                'variance_tolerance_percent' => ['nullable' => false],
                'drift_threshold_percent' => ['nullable' => true],
                'latest_funding_chain_link_id' => ['nullable' => true],
                'snoozed_until' => ['nullable' => true],
                'next_expected_at' => ['nullable' => true],
                'next_expected_confidence_low' => ['nullable' => false],
                'billing_day' => ['nullable' => true],
                'cluster_counterparty_key' => ['nullable' => true],
                '_delete_wins' => true,
                // state, cadence, variance_tolerance_percent and
                // next_expected_confidence_low all carry DB defaults, so none
                // of them belongs in the required set.
                '_create_required' => ['direction', 'detected_name', 'latest_amount_minor', 'latest_currency', 'cluster_key'],
            ],
            // AFTER recurring_series. Append-only detector output, written with
            // insertOrIgnore and never updated, so it declares no mergeable
            // field: the (series, transaction) UNIQUE is the same idempotency
            // seam on the peer, and the `id` is derived from that same pair.
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

            // The `id` is derived from the (recurring_series_id,
            // latest_occurrence_id) its own UNIQUE names; neither ever moves.
            'drift_alerts' => [
                'state' => ['nullable' => false],
                'snoozed_until' => ['nullable' => true],
                'actioned_at' => ['nullable' => true],
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

            // Unlike the tables around it this one's `id` is DERIVED, from the
            // (user_id, transaction_id) its own UNIQUE names, so both devices
            // compute the same number for the same charge. It stays out of
            // `_create_required`: the applier seeds it from the op's own pk.
            'anomaly_alerts' => [
                'state' => ['nullable' => false],
                'dismissed_as' => ['nullable' => true],
                'snoozed_until' => ['nullable' => true],
                'actioned_at' => ['nullable' => true],
                '_delete_wins' => true,
                // `state` has a DB default('open'); the amount baselines and
                // the currency are all nullable because a first-time-merchant
                // flag has no prior amount to compare against.
                '_create_required' => ['transaction_id', 'direction', 'reasons'],
            ],
            // AFTER anomaly_alerts, whose id its provenance column names.
            // Written once and deleted whole, so there is no mergeable field.
            // Uncovered, "mark as expected" muted the merchant on one device
            // while the peer kept re-raising the alert and syncing it back.
            'anomaly_suppression_rules' => [
                '_delete_wins' => true,
                '_create_required' => [
                    'detector',
                    'direction',
                    'amount_band_low_minor',
                    'amount_band_high_minor',
                    'currency',
                ],
            ],
            // A dismissal is inserted and never edited, so no mergeable field.
            // Its `id` is derived from the (user_id, insight_key) its own
            // UNIQUE names, and `insight_key` embeds a recurring_series id both
            // devices now derive too, so one dismissal is one row on both.
            'savings_insight_dismissals' => [
                '_delete_wins' => true,
                '_create_required' => ['insight_key'],
            ],
            // Acknowledging stamps `acknowledged_at` rather than deleting, so
            // that is the one column that moves. Rows with a NULL user_id are
            // system-wide and belong to no one: the backfill scopes on user_id
            // and never captures them, which is the intent — they are local.
            'system_alerts' => [
                'acknowledged_at' => ['nullable' => true],
                '_delete_wins' => true,
                // `created_at` defaults to CURRENT_TIMESTAMP and there is no
                // `updated_at` column at all on this table.
                '_create_required' => ['kind', 'severity', 'message'],
            ],
            // Promoted by the reader and never edited afterwards, so there is
            // no mergeable field; UNIQUE(user_id, email_pattern) is the
            // identity both devices derive the pk from. A NULL user_id is an
            // application seed every install already has, and never travels.
            'known_senders' => [
                '_delete_wins' => true,
                '_create_required' => ['email_pattern', 'label', 'added_at'],
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function preferenceAndScenarioRules(): array
    {
        return [
            // The reader's own settings row: no `user_id`, because its primary
            // key IS the owner. RowOwnership self-scopes it and refuses both a
            // create and a tombstone; the two column lists at the top of this
            // class say which columns travel and why the rest do not.
            'users' => [
                'period_start_day' => ['nullable' => false],
                'default_currency_view' => ['nullable' => false],
                'base_currency' => ['nullable' => true],
                'fx_online_enabled' => ['nullable' => false],
                'receipt_conflict_resolution' => ['nullable' => false],
                'recurring_detection_window_months' => ['nullable' => false],
                'recurring_income_min_amount_minor' => ['nullable' => false],
                'drift_alert_threshold_percent' => ['nullable' => false],
                'anomaly_sensitivity_percent' => ['nullable' => false],
                'anomaly_min_amount_minor' => ['nullable' => false],
                'community_settings' => ['nullable' => true],
                // The carryover fold's genesis anchor. Absent from here, a
                // device that joined by pairing held it null forever and read
                // every synced assignment as zero.
                'envelope_activated_at' => ['nullable' => true],
                // A tombstone here would end the account, and a create would
                // mint a reader the peer never signed up as. Neither is applied.
                '_delete_wins' => false,
                '_create_required' => [],
            ],
            // One row per user (UNIQUE user_id), so every column is a plain
            // per-user setting and last-writer-wins is the whole story. A
            // device does NOT get its own view mode — that is the cost of the
            // row being per-user rather than per-device.
            'user_preferences' => [
                'counterparty_index_view' => ['nullable' => false],
                'reports_index_view' => ['nullable' => false],
                // A grow-only list of skipped versions, but stored as a plain
                // JSON array of strings rather than the {v, tag} shape or_set
                // requires, so it merges as lww: two devices skipping
                // different versions concurrently keeps only the later one.
                'skipped_update_versions' => ['nullable' => false],
                'calendar_entries_accounts' => ['nullable' => true],
                'calendar_balance_accounts' => ['nullable' => true],
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
                'name' => ['nullable' => false],
                'description' => ['nullable' => true],
                '_delete_wins' => true,
                '_create_required' => ['user_id', 'name'],
            ],
            // AFTER forecast_scenarios, whose id it names. This is the
            // scenario's CONTENT: covered only by the container before, a
            // synced scenario arrived as an empty named box. `kind` is frozen
            // after insert — changing one is a remove plus a re-add.
            'forecast_scenario_mutations' => [
                'payload' => ['nullable' => false],
                'target_series_id' => ['nullable' => true],
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

    // Absent means Lww: a field entry names a strategy only where it is one of
    // the two that are not.
    public function strategyFor(string $table, string $field): MergeStrategy
    {
        /** @var array{strategy?: string}|null $fieldConfig */
        $fieldConfig = $this->rules()[$table][$field] ?? null;

        if (! is_array($fieldConfig)) {
            return MergeStrategy::Lww;
        }

        $named = $fieldConfig['strategy'] ?? null;

        return is_string($named) ? MergeStrategy::tryFrom($named) ?? MergeStrategy::Lww : MergeStrategy::Lww;
    }

    // The columns a table puts on the wire: every key that is not one of the
    // `_`-prefixed control keys.
    /**
     * @return list<string>
     */
    public function syncedColumns(string $table): array
    {
        $tableRules = $this->rules()[$table] ?? [];

        return array_values(array_filter(
            array_keys($tableRules),
            static fn (string $key): bool => ! str_starts_with($key, '_'),
        ));
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

    /**
     * @return list<string>
     */
    public function deviceLocalColumns(string $table): array
    {
        return self::DEVICE_LOCAL_COLUMNS[$table] ?? [];
    }

    /**
     * @return list<string>
     */
    public function askedOfEveryJoiner(string $table): array
    {
        return self::ASKED_OF_EVERY_JOINER[$table] ?? [];
    }

    // Columns this table never puts on the wire, whichever reason keeps them
    // off it. The capture filter asks this one question.
    /**
     * @return list<string>
     */
    public function columnsNeverOnTheWire(string $table): array
    {
        return array_merge(
            $this->deviceLocalColumns($table),
            $this->askedOfEveryJoiner($table),
        );
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
