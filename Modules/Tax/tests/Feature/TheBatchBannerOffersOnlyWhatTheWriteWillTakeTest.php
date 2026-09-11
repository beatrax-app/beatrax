<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Counterparties\Models\Counterparty;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Tax\Public\Services\TaxTagQuery;
use Modules\Tax\Tests\Support\TaxTaggingRefusalHost;

// The candidate predicate knew about a reconcile and nothing else, while the
// write also refuses a refund, a transfer and an adjustment. Four untagged
// Bol.com rows therefore offered "3 more", wrote one, and toasted "3
// transactions tagged" — three numbers about one click, two of them wrong.

/**
 * @param  array<string, mixed>  $overrides
 */
function bbcTransaction(int $userId, int $accountId, int $runId, array $overrides = []): int
{
    static $seq = 0;
    $seq++;

    return DB::table('transactions')->insertGetId(array_merge([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'bbc-'.$seq.'-'.bin2hex(random_bytes(4))),
        'posted_at' => '2026-03-15',
        'booked_at' => '2026-03-15 00:00:00',
        'value_date' => '2026-03-15',
        'amount_minor' => -5000,
        'currency' => 'EUR',
        'settled_amount_minor' => -5000,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'bol-com',
        'counterparty_name' => 'Bol.com',
        'normalization_version' => 1,
        'description' => 'Batch banner fixture',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => $seq,
        'fingerprint_version' => 3,
        'status' => 'cleared',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'bbc-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN',
        'slug' => 'asn-bbc-fixture',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0000000017',
        'default_currency' => 'EUR',
    ]);

    $run = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/bbc-fixture.csv',
        'sha256' => str_pad('7', 64, '0'),
        'uploaded_at' => now(),
        'inserted_count' => 0,
        'duplicate_count' => 0,
        'error_count' => 0,
        'status' => 'previewed',
    ]);

    $this->counterparty = Counterparty::create([
        'user_id' => $this->user->id,
        'type' => 'merchant',
        'slug' => 'bol-com-bbc-fixture',
        'display_name' => 'Bol.com',
    ]);

    $shared = ['counterparty_id' => $this->counterparty->id];

    $this->trigger = bbcTransaction($this->user->id, $account->id, $run->id, $shared);
    $this->taggable = bbcTransaction($this->user->id, $account->id, $run->id, $shared + [
        'amount_minor' => -3000,
        'settled_amount_minor' => -3000,
    ]);
    // A return crossed back, and the year total takes abs() of what it finds:
    // marked by type on a row a reader labelled, by payment_type on one the
    // detector read. Neither can carry a tag, and both were being offered.
    $this->refundByType = bbcTransaction($this->user->id, $account->id, $run->id, $shared + [
        'type' => 'refund',
        'amount_minor' => 2000,
        'settled_amount_minor' => 2000,
    ]);
    $this->refundByPaymentType = bbcTransaction($this->user->id, $account->id, $run->id, $shared + [
        'payment_type' => 'refund',
        'amount_minor' => 1500,
        'settled_amount_minor' => 1500,
    ]);
});

it('offers only the siblings a tag can actually be written to', function (): void {
    expect(app(TaxTagQuery::class)->untaggedCountForCounterparty($this->user->id, $this->trigger, 2026)->untaggedCount)
        ->toBe(1);
});

it('hands the write the same list it counted', function (): void {
    expect(app(TaxTagQuery::class)->untaggedIdsForCounterparty($this->user->id, $this->counterparty->id, 2026))
        ->toBe([$this->trigger, $this->taggable]);
});

it('writes a tag to every row it offered and to none it did not', function (): void {
    Livewire::test(TaxTaggingRefusalHost::class)
        ->call('armBatchSuggestion', [
            'counterpartyId' => $this->counterparty->id,
            'counterpartyName' => 'Bol.com',
            'untaggedCount' => 1,
            'taxYear' => 2026,
        ])
        ->call('applyBatchTag');

    $tagged = DB::table('tax_transaction_tags')->where('user_id', $this->user->id)->pluck('transaction_id')->all();

    expect($tagged)->toEqualCanonicalizing([$this->trigger, $this->taggable]);
});

it('reports the number it wrote, not the number it was handed', function (): void {
    Livewire::test(TaxTaggingRefusalHost::class)
        ->call('armBatchSuggestion', [
            'counterpartyId' => $this->counterparty->id,
            'counterpartyName' => 'Bol.com',
            'untaggedCount' => 1,
            'taxYear' => 2026,
        ])
        ->call('applyBatchTag')
        ->assertDispatched('toast', message: Lang::choice('tax::messages.batch_tagged', 2));
});
