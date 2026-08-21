<?php

declare(strict_types=1);

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

    $conn->table('transaction_search_docs')->insert([
        'transaction_id' => 9901,
        'user_id' => 1,
        'search_body' => 'Albert Heijn Groceries',
    ]);

    // Nothing keeps the two tables in step by itself, so mirror by hand what
    // SearchIndexWriter would have written.
    $conn->statement(
        'INSERT INTO transaction_search_fts(rowid, search_body) VALUES(?, ?)',
        [9901, 'Albert Heijn Groceries'],
    );

    // A bare mid-word substring only matches because the trigram tokenizer
    // indexes every three-character window; the default tokenizer would miss it.
    $rowids = $conn->select(
        'SELECT rowid FROM transaction_search_fts WHERE transaction_search_fts MATCH ?',
        ['"heijn"'],
    );

    expect($rowids)->not->toBeEmpty()
        ->and($rowids[0]->rowid)->toBe(9901);
});
