<?php

declare(strict_types=1);

namespace Modules\Ledger\Tests;

use App\Models\User;
use Carbon\CarbonImmutable;
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
        static $rowIndex = 0;
        $rowIndex++;
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
}
