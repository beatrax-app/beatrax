<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\FX\Public\Support\BundledRates;
use Modules\Ledger\Models\Account;
use Modules\Reports\Internal\Http\Livewire\ReportBuilder;

uses(RefreshDatabase::class);

// A reader with four ARS accounts, each holding an unconvertible expense, was
// told "1 account not converted": the counter held a number of CURRENCIES and
// the sentence named accounts. One DTO field, two meanings.
beforeEach(function (): void {
    app(DatabaseManager::class)->connection()
        ->table('exchange_rates')
        ->where('source', BundledRates::SOURCE)
        ->delete();
});

function fxbUser(): User
{
    /** @var User */
    return User::query()->create([
        'username' => 'fxb-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
    ]);
}

function fxbSpend(User $user, string $currency, int $minor): void
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $suffix = bin2hex(random_bytes(8));

    /** @var Account $account */
    $account = Account::query()->create([
        'user_id' => $user->id,
        'name' => $currency.' account '.$suffix,
        'slug' => 'fxb-'.strtolower($currency).'-'.$suffix,
        'kind' => 'bank',
        'iban' => 'NL00FXB'.strtoupper(bin2hex(random_bytes(6))),
        'default_currency' => $currency,
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/fxb-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'fxb-'.$suffix),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $db->connection()->table('transactions')->insert([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'import_run_id' => $runId,
        'type' => 'expense',
        'posted_at' => '2026-04-10',
        'booked_at' => '2026-04-10 10:00:00',
        'value_date' => '2026-04-10',
        'amount_minor' => $minor,
        'currency' => $currency,
        'settled_amount_minor' => $minor,
        'settled_currency' => $currency,
        'counterparty_name' => 'FXB Vendor',
        'counterparty_normalized' => 'fxb-vendor',
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint' => hash('sha256', 'fxb-tx-'.$suffix),
        'fingerprint_version' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('names the currency it could not convert rather than counting accounts it never looked at', function (): void {
    $user = fxbUser();
    fxbSpend($user, 'EUR', -113_222);
    foreach (range(1, 4) as $ignored) {
        fxbSpend($user, 'ARS', -57_500);
    }
    test()->actingAs($user);

    $html = Livewire::test(ReportBuilder::class)
        ->set('periodPreset', 'custom')
        ->set('customFrom', '2026-04-01')
        ->set('customTo', '2026-04-30')
        ->html();

    expect($html)->toContain('ARS not converted')
        ->and($html)->not->toContain('1 account not converted');
});

it('still counts accounts on the balance report, where each one really is one', function (): void {
    $user = fxbUser();
    fxbSpend($user, 'EUR', -113_222);
    foreach (range(1, 4) as $ignored) {
        fxbSpend($user, 'ARS', -57_500);
    }
    test()->actingAs($user);

    $html = Livewire::test(ReportBuilder::class)
        ->set('metric', 'net_worth')
        ->set('periodPreset', 'custom')
        ->set('customFrom', '2026-04-01')
        ->set('customTo', '2026-04-30')
        ->html();

    expect($html)->toContain('4 accounts not converted');
});
