<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Chains\Internal\ChainLinkInsertHelper;
use Modules\Chains\Internal\Http\Livewire\ChainReviewQueue;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;

// /chains/review filters hint-shaped rows out as unactionable and, in the same
// render, counts them for the header link. The empty state then told the reader
// every chain link was confirmed or rejected — with a candidate link sitting in
// the table and a link to it three lines above.

function waitingHintUser(): User
{
    return User::query()->create([
        'username' => 'waiting-hint-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    $this->user = waitingHintUser();

    $account = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'Waiting hint bank',
        'slug' => 'waiting-hint-bank-'.bin2hex(random_bytes(3)),
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);
    $run = ImportRun::query()->create([
        'user_id' => $this->user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/waiting-hint.csv',
        'sha256' => hash('sha256', 'waiting-hint-'.$this->user->id),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);
    $transfer = Transaction::query()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'type' => 'transfer_out',
        'posted_at' => '2026-05-29',
        'booked_at' => '2026-05-29 12:00:00',
        'value_date' => '2026-05-29',
        'amount_minor' => -42000,
        'currency' => 'EUR',
        'settled_amount_minor' => -42000,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'ICS Bulk Settlement',
        'counterparty_normalized' => 'ics-bulk-settlement',
        'normalization_version' => 3,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => 1,
        'fingerprint' => hash('sha256', 'waiting-hint-tx-'.$this->user->id),
        'fingerprint_version' => 3,
    ]);

    $this->db->connection()->table('chain_links')->insert([
        'id' => ChainLinkInsertHelper::idFor($this->user->id, (int) $transfer->id, null, 'ics_bulk_settle'),
        'user_id' => $this->user->id,
        'from_transaction_id' => $transfer->id,
        'to_transaction_id' => null,
        'kind' => 'ics_bulk_settle',
        'state' => 'candidate',
        'confidence' => '0.800',
        'resolver' => 'auto',
        'evidence' => json_encode([
            'statement_id' => 1,
            'unaccounted_delta_minor' => -12300,
            'tolerance_used' => 'exceeded',
            'covered_count' => 3,
            'signature_hash' => 'waiting-hint-sig',
        ]),
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);
});

it('does not call every chain link decided while a candidate hint is waiting', function (): void {
    Livewire::actingAs($this->user)
        ->test(ChainReviewQueue::class)
        ->assertSee('Nothing to review')
        ->assertDontSee('Every chain link is either confirmed or rejected')
        ->assertSeeHtml('data-testid="chain-hints-link"');
});
