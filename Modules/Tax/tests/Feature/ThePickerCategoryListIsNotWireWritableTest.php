<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Ledger\Internal\Http\Livewire\TransactionDetail;

uses(RefreshDatabase::class);

// The tax picker's category list is written only from TaxCategoryWriter, and
// the popover reads $cat->id off every row. It carried no #[Locked], so a
// tampered snapshot could put a string in the list and the render itself became
// a 500 — on every surface the picker is mounted on.

function pickerListSnapshot(string $pageHtml): string
{
    preg_match_all('/wire:snapshot="([^"]*)"/', $pageHtml, $matches);
    foreach ($matches[1] as $encoded) {
        $snapshot = html_entity_decode($encoded, ENT_QUOTES);
        if (str_contains($snapshot, '"name":"cashbook.cash-book-page"')) {
            return $snapshot;
        }
    }

    throw new RuntimeException('No wire:snapshot for the cash book on the rendered page.');
}

/**
 * @param  array<string, mixed>  $updates
 */
function pickerListTamper(string $snapshot, array $updates): TestResponse
{
    return test()->withHeaders(['X-Livewire' => 'true'])->postJson(route('default-livewire.update'), [
        '_token' => csrf_token(),
        'components' => [[
            'snapshot' => $snapshot,
            'updates' => $updates,
            'calls' => [],
        ]],
    ]);
}

function pickerListTransaction(int $userId): int
{
    $suffix = bin2hex(random_bytes(4));

    $accountId = DB::table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'Picker ASN '.$suffix,
        'slug' => 'picker-asn-'.$suffix,
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $runId = DB::table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/picker-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'picker-'.$suffix),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return DB::table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'picker-tx-'.$suffix),
        'posted_at' => '2026-03-15',
        'booked_at' => '2026-03-15 00:00:00',
        'value_date' => '2026-03-15',
        'amount_minor' => -4990,
        'currency' => 'EUR',
        'settled_amount_minor' => -4990,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'gym-vendor',
        'counterparty_name' => 'Gym Vendor BV',
        'normalization_version' => 1,
        'description' => 'Picker list fixture',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'status' => 'cleared',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'picker-list-lock',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);
});

it('refuses a browser that puts a string in the picker category list', function (): void {
    $snapshot = pickerListSnapshot($this->get('/cash')->assertOk()->getContent());

    pickerListTamper($snapshot, [
        'taxPickerTxId' => 1,
        'pickerCategories' => ['not-a-category'],
    ])->assertForbidden();
});

it('throws rather than accepting a write to the picker category list', function (): void {
    Livewire::test(TransactionDetail::class, ['transactionId' => pickerListTransaction((int) $this->user->id)])
        ->set('pickerCategories', ['not-a-category']);
})->throws(CannotUpdateLockedPropertyException::class);
