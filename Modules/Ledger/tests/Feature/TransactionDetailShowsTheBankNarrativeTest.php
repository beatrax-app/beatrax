<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Ledger\Internal\Http\Livewire\TransactionDetail;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;

uses(RefreshDatabase::class);

// /transactions/{id} rendered the counterparty, the amounts, the rate, the
// category and the note — and never transactions.description, the bank's own
// narrative that the counterparty above is RESOLVED from. Search indexes the
// column and the list renders a snippet of it in search mode, so a reader
// could find a transaction by words the detail page then refused to show.
// It is a SensitiveFieldRegistry column, so it has to reach the page through
// the codec; RenderedCiphertextGuardTest covers the ciphertext half.

function txDetailUser(): User
{
    /** @var User $user */
    $user = User::query()->create([
        'username' => 'tx-detail-narrative',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    return $user;
}

function txDetailRow(User $user, string $description): int
{
    $account = Account::create([
        'user_id' => $user->id,
        'name' => 'ASN',
        'slug' => 'asn-tx-detail-narrative-'.bin2hex(random_bytes(3)),
        'kind' => 'bank',
        'iban' => 'NL16ASNB'.random_int(1000000000, 9999999999),
        'default_currency' => 'EUR',
    ]);

    $run = ImportRun::create([
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/tx-detail-narrative.csv',
        'sha256' => str_pad(bin2hex(random_bytes(8)), 64, 'd'),
        'uploaded_at' => '2026-07-04 09:00:00',
        'status' => 'committed',
    ]);

    return (int) DB::table('transactions')->insertGetId([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => '2026-07-04',
        'booked_at' => '2026-07-04 09:00:00',
        'value_date' => '2026-07-04',
        'amount_minor' => -4215,
        'currency' => 'EUR',
        'settled_amount_minor' => -4215,
        'settled_currency' => 'EUR',
        'fx_rate_used' => null,
        'counterparty_name' => 'Albert Heijn',
        'counterparty_iban' => null,
        'counterparty_normalized' => 'albert heijn',
        'normalization_version' => 1,
        'description' => $description,
        'category_id' => null,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => 1,
        'source_ref' => 'tx-detail-narrative-'.bin2hex(random_bytes(3)),
        'fingerprint' => str_pad(bin2hex(random_bytes(8)), 64, 'd'),
        'fingerprint_version' => 3,
        'status' => 'cleared',
        'payment_type' => 'pin',
        'created_at' => '2026-07-04 09:00:00',
        'updated_at' => '2026-07-04 09:00:00',
    ]);
}

it('shows the statement description the counterparty was resolved from', function (): void {
    $user = txDetailUser();
    $id = txDetailRow($user, 'Betaalautomaat Albert Heijn 1234 PAS123');
    $this->actingAs($user);

    Livewire::test(TransactionDetail::class, ['transactionId' => $id])
        ->assertSeeHtml('data-testid="tx-detail-description"')
        ->assertSee('Betaalautomaat Albert Heijn 1234 PAS123');
});

// A row with no narrative gets no empty labelled field: an em dash under a
// heading says less than the heading not being there.
it('omits the field entirely when the row carries no description', function (): void {
    $user = txDetailUser();
    $id = txDetailRow($user, '');
    $this->actingAs($user);

    Livewire::test(TransactionDetail::class, ['transactionId' => $id])
        ->assertDontSeeHtml('data-testid="tx-detail-description"');
});

it('labels it in the reader language rather than in the seed language', function (): void {
    $user = txDetailUser();
    $id = txDetailRow($user, 'Salaris juli 2026');
    $this->actingAs($user);

    app()->setLocale('nl');

    Livewire::test(TransactionDetail::class, ['transactionId' => $id])
        ->assertSee('Omschrijving')
        ->assertDontSee('ledger::detail.description');
});
