<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Reports\Internal\Http\Livewire\ReportBuilder;

uses(RefreshDatabase::class);

function tamperedReportUser(): User
{
    /** @var User */
    return User::query()->create([
        'username' => 'tampered-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
    ]);
}

beforeEach(function (): void {
    $this->user = tamperedReportUser();

    /** @var Account $account */
    $account = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'Tampered',
        'slug' => 'tampered-'.bin2hex(random_bytes(3)),
        'kind' => 'bank',
        'iban' => 'NL00TMP'.strtoupper(bin2hex(random_bytes(6))),
        'default_currency' => 'EUR',
    ]);

    // A row, because the aggregator discovers currencies before it dispatches
    // on the dimension: with an empty ledger the dimension arm is never
    // reached and a bad ?dim= looks safe when the field would still 500.
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $this->user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/tampered.csv',
        'sha256' => hash('sha256', 'tampered-run'),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $db->connection()->table('transactions')->insert([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'import_run_id' => $runId,
        'type' => 'expense',
        'posted_at' => CarbonImmutable::now()->startOfMonth()->addDay()->toDateString(),
        'booked_at' => now(),
        'value_date' => now()->toDateString(),
        'amount_minor' => -4_200,
        'currency' => 'EUR',
        'settled_amount_minor' => -4_200,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Tampered Vendor',
        'counterparty_normalized' => 'tampered-vendor',
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint' => hash('sha256', 'tampered-tx'),
        'fingerprint_version' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($this->user);
});

it('renders the builder when a query parameter names nothing', function (string $param, string $value): void {
    Livewire::withQueryParams([$param => $value])
        ->test(ReportBuilder::class)
        ->assertOk();
})->with([
    'metric' => ['metric', 'bogus'],
    'dimension' => ['dim', 'bogus'],
    'period' => ['period', 'bogus'],
    'currency mode' => ['ccy', 'bogus'],
    'visualisation' => ['viz', 'bogus'],
    'amount direction' => ['amount_dir', 'bogus'],
    'granularity' => ['gran', 'bogus'],
]);

it('answers the export route when a query parameter names nothing', function (string $param, string $value): void {
    $this->get('/reports/export?'.$param.'='.$value)->assertSuccessful();
})->with([
    'metric' => ['metric', 'bogus'],
    'dimension' => ['dim', 'bogus'],
    'period' => ['period', 'bogus'],
    'currency mode' => ['ccy', 'bogus'],
    'visualisation' => ['viz', 'bogus'],
    'amount direction' => ['amount_dir', 'bogus'],
    'granularity' => ['gran', 'bogus'],
]);
