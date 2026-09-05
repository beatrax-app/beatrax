<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Migration\Internal\Dto\UnreconciledFieldDto;
use Modules\Migration\Internal\Pipeline\MergeApplier;
use Modules\Migration\Internal\Pipeline\ThreeWayMergeResolver;
use Modules\Migration\Models\MigrationRun;

uses(RefreshDatabase::class);

function sacUser(): User
{
    return User::query()->create([
        'username' => 'sac-user-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
}

function sacAccount(User $user): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'sac Checking',
        'slug' => 'sac-checking-'.bin2hex(random_bytes(4)),
        'kind' => 'checking',
        'iban' => 'NL-SAC-'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
    ]);
}

// A transaction whose live amount equals its baseline — the merge's APPLY
// branch — beside a staged re-statement the source denominates itself.
function sacSeed(User $user, Account $account, int $liveMinor, string $liveCurrency, int $sourceMinor, string $sourceCurrency): array
{
    $db = app(DatabaseManager::class);

    $run = ImportRun::create([
        'user_id' => $user->id,
        'source_format' => 'ynab4-csv',
        'raw_file_path' => '/tmp/sac-'.bin2hex(random_bytes(4)).'.csv',
        'sha256' => hash('sha256', 'sac-'.bin2hex(random_bytes(8))),
        'uploaded_at' => now(),
        'status' => 'confirmed',
    ]);

    $transactionId = $db->connection()->table('transactions')->insertGetId([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => '2026-03-01',
        'booked_at' => '2026-03-01 09:00:00',
        'value_date' => '2026-03-01',
        'amount_minor' => $liveMinor,
        'currency' => $liveCurrency,
        'settled_amount_minor' => $liveMinor,
        'settled_currency' => $liveCurrency,
        'counterparty_name' => 'Albert Heijn',
        'counterparty_normalized' => 'sac-const-'.bin2hex(random_bytes(4)),
        'normalization_version' => 3,
        'description' => 'Weekly shop',
        'source_format' => 'ynab4-csv',
        'import_run_id' => $run->id,
        'source_row_index' => 0,
        'source_ref' => 'SAC-'.bin2hex(random_bytes(6)),
        'fingerprint' => bin2hex(random_bytes(32)),
        'fingerprint_version' => 3,
        'status' => 'cleared',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $sourceExternalId = 'sac-row-'.bin2hex(random_bytes(4));

    $mapId = $db->connection()->table('migration_source_map')->insertGetId([
        'user_id' => $user->id,
        'source_product' => 'ynab4',
        'source_entity_type' => 'transaction',
        'source_external_id' => $sourceExternalId,
        'beatrax_entity_type' => 'transaction',
        'beatrax_id' => $transactionId,
        'natural_key' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // The baseline equals the live amount, so the merge would otherwise take
    // the APPLY branch: Beatrax has not moved, only the source has.
    $db->connection()->table('migration_import_baseline')->insert([
        'user_id' => $user->id,
        'migration_source_map_id' => $mapId,
        'field_name' => 'amount_minor',
        'baseline_value' => (string) $liveMinor,
        'imported_at' => now(),
    ]);

    $newRun = MigrationRun::create([
        'user_id' => $user->id,
        'source_product' => 'ynab4',
        'status' => 'parsed',
        'original_filename' => 'sac-new.zip',
    ]);

    $db->connection()->table('migration_staging_transactions')->insert([
        'user_id' => $user->id,
        'migration_run_id' => $newRun->id,
        'source_external_id' => $sourceExternalId,
        'account_source_external_id' => 'sac-account-ext',
        'posted_at' => '2026-03-01 00:00:00',
        'amount_minor' => $sourceMinor,
        'currency' => $sourceCurrency,
        'settled_amount_minor' => $sourceMinor,
        'settled_currency' => $sourceCurrency,
        'description' => 'Weekly shop',
        'cleared_status' => 'cleared',
        'is_split_parent' => false,
        'parent_source_external_id' => null,
    ]);

    return ['newRunId' => $newRun->id, 'transactionId' => $transactionId, 'sourceExternalId' => $sourceExternalId];
}

it('refuses an amount change whose source currency is not the live one, and reports it instead of applying it', function (): void {
    $user = sacUser();
    $seeded = sacSeed($user, sacAccount($user), -9000, 'EUR', -10000, 'USD');

    $decision = app(ThreeWayMergeResolver::class)->resolve($seeded['newRunId'], $user, 'ynab4');

    $reported = array_map(
        static fn (UnreconciledFieldDto $item): array => [
            $item->entityType, $item->fieldName, $item->localCurrency, $item->sourceCurrency,
        ],
        $decision->unreconciled,
    );

    expect($decision->applies)->toBe([]);
    expect($decision->conflicts)->toBe([]);
    expect($reported)->toBe([['transaction', 'amount_minor', 'EUR', 'USD']]);

    app(MergeApplier::class)->applyNonBudgetAssignmentChanges($seeded['newRunId'], $user, 'ynab4', $decision);

    // 100 USD must not land as 100 EUR: EntityChangeApplier rebuilds the amount
    // at the live currency, so the only safe answer is not to write.
    $row = app(DatabaseManager::class)->connection()->table('transactions')->where('id', $seeded['transactionId'])->first();
    expect((int) $row->amount_minor)->toBe(-9000);
    expect((string) $row->currency)->toBe('EUR');
    expect((int) $row->settled_amount_minor)->toBe(-9000);
});

it('still applies an amount change stated in the currency the transaction is held in', function (): void {
    $user = sacUser();
    $seeded = sacSeed($user, sacAccount($user), -9000, 'EUR', -10000, 'EUR');

    $decision = app(ThreeWayMergeResolver::class)->resolve($seeded['newRunId'], $user, 'ynab4');

    expect($decision->unreconciled)->toBe([]);
    expect($decision->applies)->toBe([[
        'entityType' => 'transaction',
        'sourceExternalId' => $seeded['sourceExternalId'],
        'fields' => ['amount_minor' => -10000],
    ]]);

    app(MergeApplier::class)->applyNonBudgetAssignmentChanges($seeded['newRunId'], $user, 'ynab4', $decision);

    $row = app(DatabaseManager::class)->connection()->table('transactions')->where('id', $seeded['transactionId'])->first();
    expect((int) $row->amount_minor)->toBe(-10000);
    expect((string) $row->currency)->toBe('EUR');
});

it('says what it refused, in the reader\'s language, naming both currencies', function (): void {
    $replace = ['local' => 'EUR', 'source' => 'USD'];

    app()->setLocale('en');
    expect(Lang::get('migration::unmapped.reason.amount_currency_mismatch', $replace))
        ->toBe('Transaction amounts were not reconciled: these transactions are kept in EUR, and this export states them in USD. Left unchanged.');

    app()->setLocale('nl');
    expect(Lang::get('migration::unmapped.reason.amount_currency_mismatch', $replace))
        ->toBe('Transactiebedragen zijn niet afgestemd: deze transacties worden bijgehouden in EUR en deze export vermeldt ze in USD. Ongewijzigd gelaten.');
});
