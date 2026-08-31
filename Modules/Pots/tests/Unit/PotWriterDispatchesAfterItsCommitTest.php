<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Pots\Public\Services\PotWriter;
use Modules\Sync\Public\Events\EntityMutated;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'dispatch-after-commit',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN',
        'slug' => 'asn-dispatch-after-commit',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0000000031',
        'default_currency' => 'EUR',
    ]);

    $this->run = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/dac.xml',
        'sha256' => str_repeat('c', 64),
        'uploaded_at' => now(),
        'status' => 'previewed',
    ]);

    Transaction::create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'import_run_id' => $this->run->id,
        'fingerprint' => hash('sha256', 'dac-credit'),
        'posted_at' => '2026-05-01',
        'booked_at' => '2026-05-01 12:00:00',
        'value_date' => '2026-05-01',
        'amount_minor' => 100000,
        'currency' => 'EUR',
        'settled_amount_minor' => 100000,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'employer',
        'counterparty_name' => 'Employer BV',
        'normalization_version' => 1,
        'description' => 'Salary',
        'type' => 'income',
        'source_format' => 'camt053',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
    ]);

    $this->writer = app(PotWriter::class);
});

// A listener that reads the row back from inside an open transaction sees a
// state no other connection has, and the outer transaction an activation walk
// would open would defer every one of these to a commit it does not control.
/**
 * @param  list<int>  $levels
 */
function pwdRecordLevels(array &$levels): void
{
    Event::listen(EntityMutated::class, static function () use (&$levels): void {
        $levels[] = DB::connection()->transactionLevel();
    });
}

it('dispatches a funding movement only once its transaction has committed', function (): void {
    $pot = $this->writer->save($this->user, 'Buffer', null, $this->account->id, null, null);

    $baseline = DB::connection()->transactionLevel();
    $levels = [];
    pwdRecordLevels($levels);

    $this->writer->fund($this->user, $pot->id, '100,00');

    expect($levels)->not->toBeEmpty()
        ->and(array_unique($levels))->toBe([$baseline]);
});

it('dispatches the archive release and the status edit only once the transaction has committed', function (): void {
    $pot = $this->writer->save($this->user, 'Buffer', '100,00', $this->account->id, null, null);

    $baseline = DB::connection()->transactionLevel();
    $levels = [];
    pwdRecordLevels($levels);

    $this->writer->archive($this->user, $pot->id);

    expect($levels)->toHaveCount(2)
        ->and(array_unique($levels))->toBe([$baseline]);
});
