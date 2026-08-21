<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP TABLE IF EXISTS transaction_search_fts');
        DB::statement('DROP TABLE IF EXISTS transaction_search_docs');

        DB::statement("
            CREATE TABLE IF NOT EXISTS transaction_search_docs (
                transaction_id INTEGER PRIMARY KEY,
                user_id        INTEGER NOT NULL,
                search_body    TEXT    NOT NULL DEFAULT ''
            )
        ");

        DB::statement('CREATE INDEX IF NOT EXISTS tsd_user_id_idx ON transaction_search_docs(user_id)');

        // Trigram is the only substring-capable tokenizer compiled into the
        // SQLite this app ships with — unicode61 is not available. External
        // content mode stores the index only, so nothing here self-maintains:
        // SearchIndexWriter has to mirror every write into both tables.
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
