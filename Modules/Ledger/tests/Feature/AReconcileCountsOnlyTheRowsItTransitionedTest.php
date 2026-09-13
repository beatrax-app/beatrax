<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Enums\ClearedStatus;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Services\TransactionStatusWriter;

// The writer selects its candidates, updates them, and compares the two counts.
// They can disagree — a row that was cleared when the select read it and is not
// by the time the update reaches it — and the branch that handles it re-reads
// which rows actually moved. Nothing exercised that branch, so the count it
// returns and the events it dispatches from it were never checked, and neither
// was the reader named on the read: an id list is what the caller asked for,
// never whose rows they are.

function arcSecondWriterFlips(DatabaseManager $db, int $transactionId): callable
{
    $done = false;

    return static function (QueryExecuted $query) use (&$done, $db, $transactionId): void {
        // The candidate select, and only it. Re-entrancy is guarded because the
        // update below is itself a query this same listener would see.
        if ($done || ! str_contains($query->sql, 'from "transactions" where "account_id"')) {
            return;
        }

        $done = true;

        $db->connection()->table('transactions')
            ->where('id', $transactionId)
            ->update([
                'status' => ClearedStatus::Reconciled->value,
                'updated_at' => '2020-01-01 00:00:00',
            ]);
    };
}

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'reconcile-count-'.bin2hex(random_bytes(3)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
    ]);

    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'Wallet',
        'slug' => 'wallet-reconcile-count-'.bin2hex(random_bytes(3)),
        'kind' => 'bank',
        'iban' => 'NL00RECONCILECOUNT'.strtoupper(bin2hex(random_bytes(2))),
        'default_currency' => Currency::Eur->value,
    ]);

    $run = $this->makeImportRun($this->user);

    $this->mine = $this->makeTransaction($this->user, $this->account, $run, [
        'status' => ClearedStatus::Cleared->value,
        'amount_minor' => -5000,
        'currency' => Currency::Eur->value,
        'settled_amount_minor' => -5000,
        'settled_currency' => Currency::Eur->value,
        'posted_at' => '2026-06-10',
    ]);

    $this->taken = $this->makeTransaction($this->user, $this->account, $run, [
        'status' => ClearedStatus::Cleared->value,
        'amount_minor' => -2500,
        'currency' => Currency::Eur->value,
        'settled_amount_minor' => -2500,
        'settled_currency' => Currency::Eur->value,
        'posted_at' => '2026-06-11',
    ]);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
});

// Two candidates, one of them already moved by the time the update runs. The
// count has to be one: a caller told "2 rows reconciled" reports a figure the
// ledger does not hold, and one event per row means the row somebody else
// reconciled would be announced twice.
it('counts the row it moved and not the one that had already gone', function (): void {
    /** @var Connection $connection */
    $connection = $this->db->connection();
    $connection->listen(arcSecondWriterFlips($this->db, (int) $this->taken->id));

    /** @var TransactionStatusWriter $writer */
    $writer = $this->app->make(TransactionStatusWriter::class);

    $moved = $writer->reconcileClearedUpTo(
        $this->user,
        (int) $this->account->id,
        CarbonImmutable::parse('2026-06-15'),
        Currency::Eur->value,
    );

    expect($moved)->toBe(1, 'the row the other writer had already reconciled is not this call\'s to count');
});

// The positive control for the case above: with nothing racing it, both rows
// move and the count is two. Without this, a writer that returned 1 for every
// call would satisfy the assertion above and nothing else.
it('counts both when nothing has taken one of them', function (): void {
    /** @var TransactionStatusWriter $writer */
    $writer = $this->app->make(TransactionStatusWriter::class);

    $moved = $writer->reconcileClearedUpTo(
        $this->user,
        (int) $this->account->id,
        CarbonImmutable::parse('2026-06-15'),
        Currency::Eur->value,
    );

    expect($moved)->toBe(2);
});
