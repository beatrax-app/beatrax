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
            'categorization_rules' => [
                'field' => ['strategy' => 'lww', 'nullable' => false],
                'match' => ['strategy' => 'lww', 'nullable' => false],
                'value' => ['strategy' => 'lww', 'nullable' => false],
                'category_id' => ['strategy' => 'lww', 'nullable' => false],
                'hits_count' => ['strategy' => 'lww', 'nullable' => false],
                'active' => ['strategy' => 'lww', 'nullable' => false],
                '_delete_wins' => true,
                '_create_required' => ['field', 'match', 'value', 'category_id', 'hits_count', 'active'],
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
            'tax_transaction_tags' => [
                'tag' => ['strategy' => 'lww', 'nullable' => false],
                '_delete_wins' => true,
                '_create_required' => ['transaction_id', 'tag'],
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
        ];
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
