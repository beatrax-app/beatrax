<?php

declare(strict_types=1);

namespace Modules\Search\Tests;

use Illuminate\Database\DatabaseManager;
use Tests\TestCase as RootTestCase;

// The fixtures seed the FTS tables themselves so search tests do not depend on
// SearchIndexWriter. A test that adds a tax note after the fact has to call
// seedFtsIndex() again, or the note never reaches the corpus.
abstract class TestCase extends RootTestCase
{
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
     * @param  array<string, mixed>  $overrides
     */
    protected function searchTestTransaction(int $userId, array $overrides = [], bool $seedFts = true): int
    {
        $db = $this->app->make(DatabaseManager::class)->connection();
        $suffix = bin2hex(random_bytes(4));

        $accountId = $overrides['account_id'] ?? $db->table('accounts')->insertGetId([
            'user_id' => $userId,
            'name' => 'Search ASN '.$suffix,
            'slug' => 'search-asn-'.$suffix,
            'kind' => 'bank',
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

        $txData = array_merge([
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
        ], $overrides);

        unset($txData['account_id']);
        $txData['account_id'] = is_int($accountId) ? $accountId : (int) $accountId;

        $txId = $db->table('transactions')->insertGetId($txData);

        // Tests of the rebuild path need transactions with an empty index, which
        // is what $seedFts = false is for.
        if ($seedFts) {
            $this->seedFtsIndex($txId, $userId);
        }

        return $txId;
    }

    // Builds the same denormalized body SearchIndexWriter produces. The chr(12)
    // separator is not trigram-indexable, so joining the fields cannot conjure a
    // match that straddles two of them.
    protected function seedFtsIndex(int $txId, int $userId): void
    {
        $db = $this->app->make(DatabaseManager::class)->connection();

        $tx = $db->table('transactions')
            ->where('id', $txId)
            ->first(['counterparty_name', 'description']);

        if ($tx === null) {
            return;
        }

        $note = $db->table('tax_transaction_tags')
            ->where('transaction_id', $txId)
            ->value('note');

        $counterpartyName = is_string($tx->counterparty_name) ? $tx->counterparty_name : '';
        $description = is_string($tx->description) ? $tx->description : '';
        $noteStr = is_string($note) ? $note : '';

        $body = $counterpartyName.chr(12).$description.chr(12).$noteStr;

        // FTS5 needs the old body handed back to it to delete the old posting.
        $existing = $db->table('transaction_search_docs')
            ->where('transaction_id', $txId)
            ->first(['search_body']);

        $oldBody = $existing !== null && is_string($existing->search_body) ? $existing->search_body : '';

        $db->table('transaction_search_docs')->upsert(
            ['transaction_id' => $txId, 'user_id' => $userId, 'search_body' => $body],
            ['transaction_id'],
            ['user_id', 'search_body'],
        );

        if ($oldBody !== '') {
            $db->statement(
                "INSERT INTO transaction_search_fts(transaction_search_fts, rowid, search_body) VALUES('delete', ?, ?)",
                [$txId, $oldBody],
            );
        }
        $db->statement(
            'INSERT INTO transaction_search_fts(rowid, search_body) VALUES(?, ?)',
            [$txId, $body],
        );
    }
}
