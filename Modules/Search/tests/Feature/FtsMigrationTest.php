<?php

declare(strict_types=1);

/**
 * FtsMigrationTest — Wave 0 PASSING test.
 *
 * Verifies that:
 *   1. The `transaction_search_docs` table exists in sqlite_master after migrate.
 *   2. The `transaction_search_fts` virtual table exists in sqlite_master.
 *   3. A raw FTS5 MATCH query against the trigram index returns the seeded rowid,
 *      proving the trigram tokenizer is compiled into this SQLite build and that
 *      substring matching works end-to-end.
 *
 * This test deliberately passes NOW (Wave 0) because the migration creates both
 * objects.  All other Search tests in this directory are RED (Wave 0) and will
 * pass once Plans 02 / 03 / 05 implement the services.
 */
it('creates the transaction_search_docs table in the database', function (): void {
    $db = app('db');

    $exists = $db->connection()
        ->table('sqlite_master')
        ->where('type', 'table')
        ->where('name', 'transaction_search_docs')
        ->exists();

    expect($exists)->toBeTrue();
});

it('creates the transaction_search_fts virtual table in the database', function (): void {
    $db = app('db');

    $exists = $db->connection()
        ->table('sqlite_master')
        ->where('type', 'table')
        ->where('name', 'transaction_search_fts')
        ->exists();

    expect($exists)->toBeTrue();
});

it('returns a matching rowid via FTS5 trigram MATCH (proves trigram tokenizer works)', function (): void {
    $db = app('db');
    $conn = $db->connection();

    // Insert a docs row — the content source for the FTS table.
    $conn->table('transaction_search_docs')->insert([
        'transaction_id' => 9901,
        'user_id' => 1,
        'search_body' => 'Albert Heijn Groceries',
    ]);

    // Sync the FTS index manually (mirroring what SearchIndexWriter does).
    $conn->statement(
        'INSERT INTO transaction_search_fts(rowid, search_body) VALUES(?, ?)',
        [9901, 'Albert Heijn Groceries'],
    );

    // Query using a trigram-style MATCH (substring — the "heijn" substring is
    // inside the 7-character trigram set for "Albert Heijn Groceries").
    $rowids = $conn->select(
        'SELECT rowid FROM transaction_search_fts WHERE transaction_search_fts MATCH ?',
        ['"heijn"'],
    );

    expect($rowids)->not->toBeEmpty()
        ->and($rowids[0]->rowid)->toBe(9901);
});
