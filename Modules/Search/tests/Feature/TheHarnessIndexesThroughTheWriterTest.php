<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Modules\Search\Internal\Services\SearchIndexWriter;
use Modules\Search\Public\Contracts\SearchIndexWriterContract;

// The fixtures used to hand-roll the writer: they built the denormalized body
// themselves and gated the FTS delete on their own rule. Sixteen feature tests
// were therefore green against a writer nothing shipped, and no test could reach
// the real one's rules at all.

function harnessRecordingWriter(): SearchIndexWriterContract
{
    $writer = new class(app(SearchIndexWriter::class)) implements SearchIndexWriterContract
    {
        /** @var list<int> */
        public array $upserted = [];

        public function __construct(private readonly SearchIndexWriterContract $inner) {}

        public function upsertForTransaction(int $transactionId, int $actorUserId): void
        {
            $this->upserted[] = $transactionId;
            $this->inner->upsertForTransaction($transactionId, $actorUserId);
        }

        public function deleteForTransaction(int $transactionId, int $actorUserId): void
        {
            $this->inner->deleteForTransaction($transactionId, $actorUserId);
        }
    };

    app()->instance(SearchIndexWriterContract::class, $writer);

    return $writer;
}

it('seeds every fixture through the production writer', function (): void {
    $writer = harnessRecordingWriter();

    $userId = $this->searchTestUser('harness-writer-user');
    $txId = $this->searchTestTransaction($userId, [
        'counterparty_name' => 'Harness Merchant',
        'description' => 'a fixture row',
    ]);

    /** @var object{upserted: list<int>} $writer */
    expect($writer->upserted)->toBe([$txId]);

    $body = app(DatabaseManager::class)->connection()
        ->table('transaction_search_docs')
        ->where('transaction_id', $txId)
        ->value('search_body');

    expect($body)->toBeString()->toContain('Harness Merchant');
});

// The delete fires because a doc row was there, not because its body had
// characters in it. An empty stored body is still a rowid FTS5 was told about,
// and the writer states that distinction in a comment nothing could reach while
// the fixtures carried their own copy of the rule.
it('hands the old body back whenever a doc row existed, empty or not', function (): void {
    $db = app(DatabaseManager::class)->connection();

    $userId = $this->searchTestUser('harness-doc-existed');
    $txId = $this->searchTestTransaction($userId, [
        'counterparty_name' => 'Harness Merchant',
        'description' => 'a fixture row',
    ], seedFts: false);

    $db->table('transaction_search_docs')->insert([
        'transaction_id' => $txId,
        'user_id' => $userId,
    ]);
    $db->statement('INSERT INTO transaction_search_fts(rowid, search_body) VALUES(?, ?)', [$txId, '']);

    $statements = [];
    DB::listen(static function (QueryExecuted $executed) use (&$statements): void {
        $statements[] = $executed->sql;
    });

    app(SearchIndexWriterContract::class)->upsertForTransaction($txId, $userId);

    $deletes = array_values(array_filter(
        $statements,
        static fn (string $sql): bool => str_contains($sql, "VALUES('delete'"),
    ));

    expect($deletes)->toHaveCount(1);
});
