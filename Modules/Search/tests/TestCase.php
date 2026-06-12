<?php

declare(strict_types=1);

namespace Modules\Search\Tests;

use Illuminate\Database\DatabaseManager;
use Tests\TestCase as RootTestCase;

/**
 * Search module-local TestCase. Extends the root TestCase.
 *
 * Provides helpers for seeding a minimal user + account + transaction
 * fixture so freshness tests can assert the synchronous write path once
 * Plan 02 lands SearchIndexWriter.
 *
 * Note: helpers write to `transactions` and `tax_transaction_tags` only —
 * they never write directly to `transaction_search_docs` or
 * `transaction_search_fts`.  That is the writer's job; tests assert via
 * the real services.
 */
abstract class TestCase extends RootTestCase
{
    /**
     * Inserts a minimal user row and returns its id.
     */
    protected function searchTestUser(string $username): int
    {
        return $this->app->make(DatabaseManager::class)
            ->connection()
            ->table('users')
            ->insertGetId([
                'username' => $username,
                'password' => bcrypt('test'),
                'period_start_day' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * Inserts an account + import run + transaction for the given user
     * and returns the transaction id.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function searchTestTransaction(int $userId, array $overrides = []): int
    {
        $db = $this->app->make(DatabaseManager::class)->connection();
        $suffix = bin2hex(random_bytes(4));

        $accountId = $db->table('accounts')->insertGetId([
            'user_id' => $userId,
            'name' => 'Search ASN '.$suffix,
            'slug' => 'search-asn-'.$suffix,
            'kind' => 'asn',
            'iban' => 'NL00ASNB'.strtoupper($suffix),
            'default_currency' => 'EUR',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $runId = $db->table('import_runs')->insertGetId([
            'user_id' => $userId,
            'source_format' => 'asn-csv',
            'raw_file_path' => '/tmp/srch-run-'.$suffix.'.csv',
            'sha256' => hash('sha256', 'srch-run-'.$suffix),
            'uploaded_at' => now(),
            'status' => 'committed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $db->table('transactions')->insertGetId(array_merge([
            'user_id' => $userId,
            'account_id' => $accountId,
            'import_run_id' => $runId,
            'fingerprint' => hash('sha256', 'srch-tx-'.bin2hex(random_bytes(8))),
            'fingerprint_version' => 3,
            'posted_at' => '2026-01-15',
            'booked_at' => '2026-01-15 00:00:00',
            'value_date' => '2026-01-15',
            'type' => 'expense',
            'amount_minor' => -4990,
            'currency' => 'EUR',
            'settled_amount_minor' => -4990,
            'settled_currency' => 'EUR',
            'counterparty_name' => 'Albert Heijn',
            'counterparty_normalized' => 'albert heijn',
            'normalization_version' => 1,
            'description' => 'Groceries at Albert Heijn',
            'source_format' => 'asn-csv',
            'source_row_index' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }
}
