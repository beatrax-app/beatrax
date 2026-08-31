<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Tax\Internal\Services\TaxCsvExporter;
use Modules\Tax\Internal\Services\TaxYearQuery;

const LOAS_ORIGINAL_AMOUNT_COLUMN = 10;

function loasUser(string $username): User
{
    /** @var User */
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function loasAccount(DatabaseManager $db, int $userId): int
{
    $suffix = bin2hex(random_bytes(4));

    return $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'LOAS ASN '.$suffix,
        'slug' => 'loas-asn-'.$suffix,
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function loasImportRun(DatabaseManager $db, int $userId): int
{
    $suffix = bin2hex(random_bytes(4));

    return $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/loas-run-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'loas-run-'.$suffix),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function loasTransaction(DatabaseManager $db, int $userId, int $accountId, int $runId, int $nativeMinor, int $settledMinor): int
{
    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'loas-tx-'.bin2hex(random_bytes(8))),
        'posted_at' => '2026-02-10',
        'booked_at' => '2026-02-10 00:00:00',
        'value_date' => '2026-02-10',
        'amount_minor' => $nativeMinor,
        'currency' => 'USD',
        'settled_amount_minor' => $settledMinor,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'loas-vendor',
        'counterparty_name' => 'LOAS Vendor BV',
        'normalization_version' => 1,
        'description' => 'LOAS cross-currency card purchase',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function loasSpendCategory(DatabaseManager $db, int $userId): int
{
    $suffix = bin2hex(random_bytes(4));

    return $db->connection()->table('categories')->insertGetId([
        'user_id' => $userId,
        'name' => 'LOAS Spend '.$suffix,
        'slug' => 'loas-spend-'.$suffix,
        'kind' => 'expense',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function loasDeductionCategory(DatabaseManager $db, int $userId): int
{
    return $db->connection()->table('tax_deduction_categories')->insertGetId([
        'user_id' => $userId,
        'name' => 'LOAS Deduction',
        'short_name' => 'LOA',
        'status' => 'active',
        'sort_order' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function loasLeg(DatabaseManager $db, int $userId, int $txId, int $categoryId, int $settledMinor, int $sortOrder): int
{
    return $db->connection()->table('transaction_splits')->insertGetId([
        'user_id' => $userId,
        'transaction_id' => $txId,
        'category_id' => $categoryId,
        'settled_amount_minor' => $settledMinor,
        'settled_currency' => 'EUR',
        'note' => null,
        'sort_order' => $sortOrder,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function loasTag(DatabaseManager $db, int $userId, int $txId, ?int $deductionCategoryId, ?int $legId): void
{
    $db->connection()->table('tax_transaction_tags')->insert([
        'user_id' => $userId,
        'transaction_id' => $txId,
        'transaction_split_id' => $legId,
        'deduction_category_id' => $deductionCategoryId,
        'tax_year_override' => null,
        'note' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * The "Original" column exactly as `/tax` and the CSV read it: leg id => native minor.
 *
 * @return array<int, int>
 */
function loasOriginalColumnByLeg(int $userId, int $year = 2026): array
{
    /** @var TaxYearQuery $query */
    $query = app(TaxYearQuery::class);

    $column = [];
    foreach ($query->forUser($userId, $year)->categories as $category) {
        /** @var array<string, mixed> $category */
        /** @var list<array<string, mixed>> $rows */
        $rows = $category['rows'];
        foreach ($rows as $row) {
            $column[(int) $row['transactionSplitId']] = (int) $row['amountMinor'];
        }
    }

    return $column;
}

/**
 * Tags every leg of one cross-currency parent and returns the leg ids in sort order.
 *
 * @param  list<int>  $legSettledMinor
 * @return list<int>
 */
function loasTaggedSplit(DatabaseManager $db, int $userId, int $nativeMinor, int $settledMinor, array $legSettledMinor): array
{
    $txId = loasTransaction($db, $userId, loasAccount($db, $userId), loasImportRun($db, $userId), $nativeMinor, $settledMinor);
    $deduction = loasDeductionCategory($db, $userId);
    $spend = loasSpendCategory($db, $userId);

    $legIds = [];
    foreach ($legSettledMinor as $index => $legMinor) {
        $legId = loasLeg($db, $userId, $txId, $spend, $legMinor, $index);
        loasTag($db, $userId, $txId, $deduction, $legId);
        $legIds[] = $legId;
    }

    return $legIds;
}

it('gives the three legs of a $30.00 charge that settled at EUR 27.23 an Original column that sums to $30.00', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $user = loasUser('loas-three-way');
    $legIds = loasTaggedSplit($db, $user->id, -3000, -2723, [-908, -908, -907]);

    $column = loasOriginalColumnByLeg($user->id);

    expect(array_sum($column))->toBe(-3000)
        ->and($column[$legIds[0]])->toBe(-1001)
        ->and($column[$legIds[1]])->toBe(-1000)
        ->and($column[$legIds[2]])->toBe(-999);
});

it('sums the CSV original_amount column of those same three legs to 30.00', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $user = loasUser('loas-three-way-csv');
    loasTaggedSplit($db, $user->id, -3000, -2723, [-908, -908, -907]);

    /** @var TaxCsvExporter $exporter */
    $exporter = app(TaxCsvExporter::class);
    $lines = array_values(array_filter(explode("\n", trim($exporter->export($user, 2026)))));

    $amounts = [];
    foreach (array_slice($lines, 1) as $line) {
        /** @var list<string> $columns */
        $columns = str_getcsv($line);
        $amounts[] = $columns[LOAS_ORIGINAL_AMOUNT_COLUMN];
    }
    sort($amounts);

    expect($amounts)->toBe(['9.99', '10.00', '10.01'])
        ->and(array_sum(array_map(static fn (string $amount): int => (int) round(((float) $amount) * 100), $amounts)))
        ->toBe(3000);
});

it('keeps the legs summing to the parent across two hundred random cross-currency splits', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $user = loasUser('loas-property');
    $accountId = loasAccount($db, $user->id);
    $runId = loasImportRun($db, $user->id);
    $deduction = loasDeductionCategory($db, $user->id);
    $spend = loasSpendCategory($db, $user->id);

    mt_srand(20260830);

    /** @var array<int, int> $expectedByTransaction */
    $expectedByTransaction = [];
    /** @var array<int, int> $transactionByLeg */
    $transactionByLeg = [];

    for ($case = 0; $case < 200; $case++) {
        $nativeMinor = -mt_rand(100, 500_000);
        $legCount = mt_rand(2, 5);
        $settledMinor = -mt_rand($legCount * 3, 500_000);

        $cuts = [0];
        while (count($cuts) < $legCount) {
            $cut = mt_rand(1, abs($settledMinor) - 1);
            if (! in_array($cut, $cuts, true)) {
                $cuts[] = $cut;
            }
        }
        $cuts[] = abs($settledMinor);
        sort($cuts);

        $txId = loasTransaction($db, $user->id, $accountId, $runId, $nativeMinor, $settledMinor);
        $expectedByTransaction[$txId] = $nativeMinor;

        for ($index = 0; $index < $legCount; $index++) {
            $legId = loasLeg($db, $user->id, $txId, $spend, -($cuts[$index + 1] - $cuts[$index]), $index);
            loasTag($db, $user->id, $txId, $deduction, $legId);
            $transactionByLeg[$legId] = $txId;
        }
    }

    $summed = [];
    foreach (loasOriginalColumnByLeg($user->id) as $legId => $nativeMinor) {
        $txId = $transactionByLeg[$legId];
        $summed[$txId] = ($summed[$txId] ?? 0) + $nativeMinor;
    }

    ksort($summed);
    ksort($expectedByTransaction);

    expect($summed)->toBe($expectedByTransaction);
});

it('still hands a mixed-sign leg set an Original column that sums to the parent', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $user = loasUser('loas-mixed-sign');
    $legIds = loasTaggedSplit($db, $user->id, -3000, -2723, [-3000, 277]);

    $column = loasOriginalColumnByLeg($user->id);

    expect(array_sum($column))->toBe(-3000)
        ->and($column[$legIds[0]])->toBe(-3305)
        ->and($column[$legIds[1]])->toBe(305);
});

it('leaves an untagged leg out of the reader\'s column while the parent\'s full leg set still carries the whole native amount', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $user = loasUser('loas-partial-tagging');
    $txId = loasTransaction($db, $user->id, loasAccount($db, $user->id), loasImportRun($db, $user->id), -3000, -2723);
    $spend = loasSpendCategory($db, $user->id);
    $deduction = loasDeductionCategory($db, $user->id);

    $tagged = loasLeg($db, $user->id, $txId, $spend, -908, 0);
    loasLeg($db, $user->id, $txId, $spend, -908, 1);
    loasLeg($db, $user->id, $txId, $spend, -907, 2);
    loasTag($db, $user->id, $txId, $deduction, $tagged);

    $column = loasOriginalColumnByLeg($user->id);

    // The tagged leg reports its own share of the parent's native amount, and
    // the share it is given is the one it would have had if every leg were on
    // the page — tagging one leg cannot move another leg's cent.
    expect($column)->toBe([$tagged => -1001]);
});
