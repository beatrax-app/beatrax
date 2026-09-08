<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Search\Public\Contracts\SearchIndexRepairContract;
use Modules\Search\Public\Contracts\SearchIndexWriterContract;

uses(RefreshDatabase::class);

// The repair queue is asked to upsert, and a failed DELETE is owed on the same
// queue. So the drain meets rows whose transaction is gone, and the end state
// for one of those is no doc at all — retiring the debt while leaving the words
// of a deleted transaction findable is the opposite of the repair.
function goneRowUser(): User
{
    return User::query()->create([
        'username' => 'gone-row-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

function goneRowTransaction(DatabaseManager $db, int $userId): int
{
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'Gone row test',
        'slug' => 'gone-row-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
        'created_at' => '2026-08-12 00:00:00',
        'updated_at' => '2026-08-12 00:00:00',
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/gone-row.csv',
        'sha256' => hash('sha256', 'gone-row-'.bin2hex(random_bytes(8))),
        'uploaded_at' => '2026-08-12 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-08-12 00:00:00',
        'updated_at' => '2026-08-12 00:00:00',
    ]);

    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'gone-row-'.bin2hex(random_bytes(8))),
        'posted_at' => '2026-08-12',
        'booked_at' => '2026-08-12 10:00:00',
        'value_date' => '2026-08-12',
        'amount_minor' => -4200,
        'currency' => 'EUR',
        'settled_amount_minor' => -4200,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'hollandsche manege',
        'counterparty_name' => 'Hollandsche Manege',
        'normalization_version' => 3,
        'description' => 'stalling en hooi',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => '2026-08-12 00:00:00',
        'updated_at' => '2026-08-12 00:00:00',
    ]);
}

it('takes the index doc with a transaction the drain finds gone', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    /** @var SearchIndexWriterContract $writer */
    $writer = app(SearchIndexWriterContract::class);
    /** @var SearchIndexRepairContract $repairs */
    $repairs = app(SearchIndexRepairContract::class);

    $user = goneRowUser();
    $txId = goneRowTransaction($db, $user->id);

    $writer->upsertForTransaction($txId, $user->id);

    expect($db->connection()->table('transaction_search_docs')->where('transaction_id', $txId)->exists())
        ->toBeTrue('nothing was indexed, so the case below would pass without proving anything');

    // The shape a failed DELETE takes: the row goes, the debt is owed, and the
    // drain arrives at a coordinate whose transaction no longer exists.
    $repairs->owe($user->id, $txId);
    $db->connection()->table('transactions')->where('id', $txId)->delete();

    $writer->upsertForTransaction($txId, $user->id);

    expect($db->connection()->table('transaction_search_docs')->where('transaction_id', $txId)->exists())
        ->toBeFalse('the words of a deleted transaction are still findable')
        ->and($db->connection()->table('search_index_repairs')->where('transaction_id', $txId)->exists())
        ->toBeFalse('the debt outlived the row it was owed for');
});
