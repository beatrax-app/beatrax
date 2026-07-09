<?php

declare(strict_types=1);

namespace Modules\Ledger\Tests;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Tests\TestCase as RootTestCase;

/**
 * Ledger module-local TestCase. Provides a `canonical()` factory that returns
 * a CanonicalTransaction filled with sane defaults so individual tests can
 * override only the field they exercise, plus a `makeTransaction()` helper
 * that persists a fully-formed Transaction row for query-service tests.
 */
abstract class TestCase extends RootTestCase
{
    /**
     * Row counter for `makeTransaction()`. Lives on the instance (not on a
     * static inside the method) so each test starts from zero — deterministic
     * fingerprints and posted_at offsets keep snapshot diffs and debug logs
     * stable when individual tests are run in isolation.
     */
    private int $rowIndex = 0;

    /**
     * Build a CanonicalTransaction with sensible defaults. Callers override
     * only the keys they care about.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function canonical(array $overrides = []): CanonicalTransaction
    {
        $defaults = [
            'userId' => null,
            'accountId' => 1,
            'type' => 'expense',
            'postedAt' => CarbonImmutable::parse('2026-05-03'),
            'bookedAt' => CarbonImmutable::parse('2026-05-03 12:00:00'),
            'valueDate' => CarbonImmutable::parse('2026-05-03'),
            'amountMinor' => -1299,
            'currency' => 'EUR',
            'settledAmountMinor' => -1299,
            'settledCurrency' => 'EUR',
            'fxRateUsed' => null,
            'counterpartyName' => 'AH Amsterdam',
            'counterpartyIban' => null,
            'counterpartyNormalized' => 'ah amsterdam',
            'normalizationVersion' => 1,
            'description' => 'Albert Heijn weekly groceries',
            'categoryId' => null,
            'sourceFormat' => 'asn-csv',
            'importRunId' => 1,
            'sourceRowIndex' => 0,
            'sourceRef' => 'ASN-REF-001',
            'rawPayload' => null,
        ];

        $merged = array_merge($defaults, $overrides);

        return new CanonicalTransaction(
            userId: $merged['userId'],
            accountId: $merged['accountId'],
            type: $merged['type'],
            postedAt: $merged['postedAt'],
            bookedAt: $merged['bookedAt'],
            valueDate: $merged['valueDate'],
            amountMinor: $merged['amountMinor'],
            currency: $merged['currency'],
            settledAmountMinor: $merged['settledAmountMinor'],
            settledCurrency: $merged['settledCurrency'],
            fxRateUsed: $merged['fxRateUsed'],
            counterpartyName: $merged['counterpartyName'],
            counterpartyIban: $merged['counterpartyIban'],
            counterpartyNormalized: $merged['counterpartyNormalized'],
            normalizationVersion: $merged['normalizationVersion'],
            description: $merged['description'],
            categoryId: $merged['categoryId'],
            sourceFormat: $merged['sourceFormat'],
            importRunId: $merged['importRunId'],
            sourceRowIndex: $merged['sourceRowIndex'],
            sourceRef: $merged['sourceRef'],
            rawPayload: $merged['rawPayload'],
        );
    }

    /**
     * Create an ImportRun for the given user. Provides the foreign-key target
     * that every Transaction row needs (`transactions.import_run_id`).
     */
    protected function makeImportRun(User $user, string $sha = '0000000000000000000000000000000000000000000000000000000000000000'): ImportRun
    {
        return ImportRun::create([
            'user_id' => $user->id,
            'source_format' => 'asn-csv',
            'raw_file_path' => '/tmp/fixture.csv',
            'sha256' => $sha,
            'uploaded_at' => CarbonImmutable::parse('2026-05-01 12:00:00'),
            'inserted_count' => 0,
            'duplicate_count' => 0,
            'error_count' => 0,
            'status' => 'previewed',
        ]);
    }

    /**
     * Persist one Transaction row for query-service tests. Defaults align
     * with the no-counterparty sentinel and the sign-to-type rules so
     * callers can override only the field under test.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function makeTransaction(User $user, Account $account, ImportRun $run, array $overrides = []): Transaction
    {
        $this->rowIndex++;
        $rowIndex = $this->rowIndex;
        $fingerprint = str_pad((string) $rowIndex, 64, '0', STR_PAD_LEFT);

        $amountMinor = $overrides['amount_minor'] ?? -1299;
        $type = $overrides['type'] ?? match (true) {
            $amountMinor > 0 => 'income',
            $amountMinor < 0 => 'expense',
            default => 'adjustment',
        };

        $postedAt = $overrides['posted_at'] ?? '2026-05-'.sprintf('%02d', min(28, $rowIndex));
        $bookedAt = $overrides['booked_at'] ?? $postedAt.' 12:00:00';

        return Transaction::create(array_merge([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'type' => $type,
            'posted_at' => $postedAt,
            'booked_at' => $bookedAt,
            'value_date' => $postedAt,
            'amount_minor' => $amountMinor,
            'currency' => 'EUR',
            'settled_amount_minor' => $amountMinor,
            'settled_currency' => 'EUR',
            'counterparty_name' => "Merchant {$rowIndex}",
            'counterparty_normalized' => "merchant {$rowIndex}",
            'normalization_version' => 1,
            'source_format' => 'asn-csv',
            'import_run_id' => $run->id,
            'source_row_index' => $rowIndex,
            'fingerprint' => $fingerprint,
            'fingerprint_version' => 1,
        ], $overrides));
    }

    /**
     * Insert one raw transaction row stamped at normalization_version=2.
     * Uses DatabaseManager directly so the stored fingerprint stays exactly
     * as supplied — Eloquent observers and fillable bypass rules would
     * otherwise rewrite the value mid-test.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function seedV2Row(array $overrides = []): int
    {
        /** @var DatabaseManager $db */
        $db = $this->app->make(DatabaseManager::class);

        $this->rowIndex++;
        $rowIndex = $this->rowIndex;

        $defaults = [
            'user_id' => $this->user->id ?? null,
            'account_id' => $this->account->id ?? null,
            'type' => 'expense',
            'posted_at' => '2026-04-12',
            'booked_at' => '2026-04-12 09:14:33',
            'value_date' => '2026-04-12',
            'amount_minor' => -1299,
            'currency' => 'EUR',
            'settled_amount_minor' => -1299,
            'settled_currency' => 'EUR',
            'counterparty_name' => "Merchant {$rowIndex}",
            'counterparty_normalized' => "merchant {$rowIndex}",
            'normalization_version' => 2,
            'source_format' => 'asn-csv',
            'import_run_id' => $this->importRun->id ?? null,
            'source_row_index' => $rowIndex,
            'source_ref' => "REF-{$rowIndex}",
            'fingerprint' => str_pad((string) $rowIndex, 64, '0', STR_PAD_LEFT),
            'fingerprint_version' => 2,
            'status' => 'cleared',
            'created_at' => CarbonImmutable::now()->toDateTimeString(),
            'updated_at' => CarbonImmutable::now()->toDateTimeString(),
        ];

        $row = array_merge($defaults, $overrides);

        return (int) $db->connection()->table('transactions')->insertGetId($row);
    }

    /**
     * Seed two rows that share every v3 tuple dimension but differ on
     * `source_ref`. The current schema's composite UNIQUE on transactions
     * already enforces the v3 tuple so the rows cannot coexist with the
     * index in place — this helper drops it for the duration of the test,
     * simulating data that pre-dates the v3 index swap. The dropped index
     * is the trigger for the rederive command's collision pre-check; the
     * RefreshDatabase trait restores a clean schema for the next test.
     */
    protected function seedCollidingV2Rows(): void
    {
        /** @var DatabaseManager $db */
        $db = $this->app->make(DatabaseManager::class);
        $connection = $db->connection();

        $connection->statement('DROP INDEX IF EXISTS transactions_fingerprint_uq');

        $shared = [
            'posted_at' => '2026-04-12',
            'booked_at' => '2026-04-12 09:14:33',
            'value_date' => '2026-04-12',
            'amount_minor' => -1299,
            'counterparty_name' => 'AH Amsterdam',
            'counterparty_normalized' => 'ah amsterdam',
        ];

        $this->seedV2Row(array_merge($shared, [
            'source_ref' => 'REF-A',
            'fingerprint' => str_repeat('a', 64),
        ]));
        $this->seedV2Row(array_merge($shared, [
            'source_ref' => 'REF-B',
            'fingerprint' => str_repeat('b', 64),
        ]));
    }

    /**
     * Seed two v2 rows whose v3 tuples differ (different bookedAt seconds
     * + different counterparty_normalized) so re-derive runs cleanly.
     */
    protected function seedTwoNonCollidingV2Rows(): void
    {
        $this->seedV2Row([
            'booked_at' => '2026-04-12 09:14:33',
            'counterparty_name' => 'AH Amsterdam',
            'counterparty_normalized' => 'ah amsterdam',
            'amount_minor' => -1299,
            'source_ref' => 'REF-1',
        ]);
        $this->seedV2Row([
            'booked_at' => '2026-04-12 09:14:34',
            'counterparty_name' => 'Jumbo Amsterdam',
            'counterparty_normalized' => 'jumbo amsterdam',
            'amount_minor' => -2499,
            'source_ref' => 'REF-2',
        ]);
    }

    /**
     * Seed one row that already carries normalization_version=3 so the
     * rederive command treats it as up-to-date and skips it.
     */
    protected function seedOneV3Row(): void
    {
        $this->seedV2Row([
            'normalization_version' => 3,
            'fingerprint_version' => 3,
            'source_ref' => 'EREF-V3',
        ]);
    }
}
