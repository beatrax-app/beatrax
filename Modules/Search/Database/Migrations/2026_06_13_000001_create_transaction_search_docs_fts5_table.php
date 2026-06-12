<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Creates the FTS5 search index substrate for the Search module.
 *
 * Two objects are created:
 *
 *  1. `transaction_search_docs` — a plain normalised helper table (one row
 *     per transaction) that aggregates the searchable text for that
 *     transaction: counterparty name + description + tax note.  The table
 *     acts as the "content" source for the FTS5 virtual table.
 *
 *  2. `transaction_search_fts` — an FTS5 virtual table using external-content
 *     mode (`content='transaction_search_docs'`) with the `trigram` tokenizer.
 *     Trigram is the only sub-string-capable tokenizer compiled into this
 *     SQLite build; `unicode61` is NOT available (confirmed via live test).
 *
 * Idempotency: both objects are created with `IF NOT EXISTS`; `down()`
 * drops both with `IF EXISTS`.  The migration is safe to re-run (full-history
 * constraint from CLAUDE.md).
 *
 * Index freshness: the FTS table is NOT self-maintaining — the PHP-layer
 * SearchIndexWriter (Plan 02) drives upserts/deletes synchronously on every
 * relevant write (D-23).  The `search:reindex` command (Plan 02) can rebuild
 * the entire index from `transaction_search_docs` via
 * `INSERT INTO transaction_search_fts(transaction_search_fts) VALUES('rebuild')`.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Drop both tables first for idempotent re-run safety.
        DB::statement('DROP TABLE IF EXISTS transaction_search_fts');
        DB::statement('DROP TABLE IF EXISTS transaction_search_docs');

        // Denormalized helper table (one row per transaction, all searchable text).
        DB::statement("
            CREATE TABLE IF NOT EXISTS transaction_search_docs (
                transaction_id INTEGER PRIMARY KEY,
                user_id        INTEGER NOT NULL,
                search_body    TEXT    NOT NULL DEFAULT ''
            )
        ");

        DB::statement('CREATE INDEX IF NOT EXISTS tsd_user_id_idx ON transaction_search_docs(user_id)');

        // FTS5 virtual table with trigram tokenizer.
        // tokenize=trigram: exact substring matching, case-insensitive, no stemming required.
        // content='transaction_search_docs': external-content mode — the FTS table stores
        // only the index, not a copy of the text.  The PHP writer is responsible for keeping
        // both tables in sync (see SearchIndexWriter, Plan 02).
        DB::statement("
            CREATE VIRTUAL TABLE IF NOT EXISTS transaction_search_fts
            USING fts5(
                search_body,
                content       = 'transaction_search_docs',
                content_rowid = 'transaction_id',
                tokenize      = 'trigram'
            )
        ");
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS transaction_search_fts');
        DB::statement('DROP TABLE IF EXISTS transaction_search_docs');
    }
};
