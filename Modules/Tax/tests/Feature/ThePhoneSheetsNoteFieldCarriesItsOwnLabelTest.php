<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\PatternScan;
use Modules\Ledger\Internal\Http\Livewire\TransactionsList;

uses(RefreshDatabase::class);

// The fixtures here book at an absolute date and TransactionsList queries a
// rolling recent(daysBack: 90) off the real clock, so the pair has an expiry
// date. TaxBadgeSurfacesTest reached its on 2026-08-31; this freezes the clock
// before the same arithmetic reaches this one.
beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-14 10:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

// The popover body is included twice — once for the desktop popover, once for
// the phone bottom sheet — and both copies are in the document at all times,
// one hidden by a breakpoint class. Measured on a 375pt iPhone with the sheet
// open: the visible textarea reported labels.length 0 and both labels resolved
// to the 0x0 hidden one, so tapping "Note (optional)" focused nothing.
function noteLabelUser(string $username = 'note-label-user'): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'test-password',
        'is_developer' => false,
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function noteLabelTx(DatabaseManager $db, int $userId): int
{
    $suffix = bin2hex(random_bytes(4));

    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'Note ASN '.$suffix,
        'slug' => 'note-asn-'.$suffix,
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/note-run-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'note-run-'.$suffix),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'note-tx-'.bin2hex(random_bytes(8))),
        'posted_at' => '2026-06-01',
        'booked_at' => '2026-06-01 00:00:00',
        'value_date' => '2026-06-01',
        'amount_minor' => -3000,
        'currency' => 'EUR',
        'settled_amount_minor' => -3000,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Note Vendor BV',
        'counterparty_normalized' => 'note-vendor',
        'normalization_version' => 1,
        'description' => 'Note label test transaction',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('gives each rendered tax picker its own note id so both labels bind', function (): void {
    $user = noteLabelUser();
    $db = app(DatabaseManager::class);
    $txId = noteLabelTx($db, $user->id);

    $html = Livewire::actingAs($user)->test(TransactionsList::class)
        ->dispatch('tax-tag', id: $txId)
        ->html();

    $ids = PatternScan::all('/id="(tax-picker-note-[^"]+)"/', $html);
    expect($ids[1])->toHaveCount(2);
    expect(array_unique($ids[1]))->toHaveCount(2);

    $fors = PatternScan::all('/for="(tax-picker-note-[^"]+)"/', $html);
    expect($fors[1])->toHaveCount(2);
    expect(array_unique($fors[1]))->toHaveCount(2);

    // Each label names an id that exists, so neither points at the other copy.
    expect(array_values(array_unique($fors[1])))->toEqualCanonicalizing(array_values(array_unique($ids[1])));
});
