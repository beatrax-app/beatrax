<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Enums\InboxMessageStatus;

// No rows are needed: nothing in the app runs ANALYZE, so SQLite plans this
// from the schema alone and answers the same way on an empty mailbox as on the
// years of parsed messages the walk has to step over to find a new one.
$iwsPlan = static function (DatabaseManager $db): string {
    $query = $db->connection()->table('inbox_messages')
        ->where('status', InboxMessageStatus::Fetched->value)
        ->orderBy('id');

    return implode("\n", array_map(
        static fn (object $row): string => (string) ($row->detail ?? ''),
        $db->connection()->select('EXPLAIN QUERY PLAN '.$query->toSql(), $query->getBindings()),
    ));
};

it('carries an index the status predicate leads with', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $row = $db->connection()->selectOne(
        "SELECT sql FROM sqlite_master WHERE type = 'index' AND name = 'inbox_messages_status_idx'",
    );

    expect($row)->not->toBeNull();
});

// The query is user-agnostic by design and its consumer enforces the per-user
// scope, so `inbox_messages_user_id_status_index` cannot answer it. The set it
// looks for shrinks as the mailbox it reads to find that set grows.
it('seeks the unprocessed messages instead of scanning the mailbox for them', function () use ($iwsPlan): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    expect($iwsPlan($db))
        ->toContain('SEARCH inbox_messages USING INDEX inbox_messages_status_idx')
        ->and($iwsPlan($db))->not->toContain('SCAN inbox_messages');
});
