<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Exceptions\NotAuthenticatedException;
use Modules\Ledger\Models\Account;
use Modules\Mobile\Public\Services\ShareSheetExport;
use Modules\Reports\Internal\Aggregation\PeriodPresetResolver;
use Modules\Reports\Internal\Http\Livewire\ReportBuilder;
use Modules\Reports\Internal\Services\ReportCsvExporter;
use Symfony\Component\HttpFoundation\StreamedResponse;

uses(RefreshDatabase::class);

function rerUser(): User
{
    /** @var User */
    return User::query()->create([
        'username' => 'rer-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
    ]);
}

function rerAccount(User $user): Account
{
    /** @var Account */
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'RER',
        'slug' => 'rer-'.bin2hex(random_bytes(3)),
        'kind' => 'bank',
        'iban' => 'NL00RER'.strtoupper(bin2hex(random_bytes(6))),
        'default_currency' => 'EUR',
    ]);
}

it('streams a CSV built from the query string for an authenticated user', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = rerUser();
    $account = rerAccount($user);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/rer.csv',
        'sha256' => hash('sha256', 'rer-run'),
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
        'posted_at' => CarbonImmutable::now()->startOfMonth()->addDay()->toDateString(),
        'booked_at' => now(),
        'value_date' => now()->toDateString(),
        'amount_minor' => -4_200,
        'currency' => 'EUR',
        'settled_amount_minor' => -4_200,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'RER Vendor',
        'counterparty_normalized' => 'rer-vendor',
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint' => hash('sha256', 'rer-tx'),
        'fingerprint_version' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = test()->actingAs($user)->get('/reports/export?metric=spend&dim=account&period=this_month');

    $response->assertOk();
    expect($response->baseResponse)->toBeInstanceOf(StreamedResponse::class);

    ob_start();
    $response->baseResponse->sendContent();
    $csv = (string) ob_get_clean();

    expect($csv)->toContain('RER');
});

it('returns an empty stream when the export action runs without an authenticated user', function (): void {
    $anonymous = new class implements CurrentUser
    {
        public function id(): int
        {
            throw new NotAuthenticatedException;
        }

        public function user(): User
        {
            throw new NotAuthenticatedException;
        }

        public function periodStartDay(): int
        {
            throw new NotAuthenticatedException;
        }

        public function isAuthenticated(): bool
        {
            return false;
        }
    };

    $component = new ReportBuilder;
    $response = $component->export(app(ResponseFactory::class), app(ReportCsvExporter::class), $anonymous, app(PeriodPresetResolver::class), app(ShareSheetExport::class));

    expect($response)->toBeInstanceOf(StreamedResponse::class);

    ob_start();
    $response->sendContent();
    expect((string) ob_get_clean())->toBe('');
});
