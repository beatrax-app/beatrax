<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Actions\RecordTransactions;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

uses(RefreshDatabase::class);

/**
 * SC-3 / D-04: re-importing the identical CanonicalTransaction must never
 * reset a user-set status. Per RESEARCH.md Pitfall 1, `RecordTransactions`
 * already dedupes via `insertOrIgnore` on the fingerprint UNIQUE index —
 * a fingerprint conflict leaves the ENTIRE existing row untouched (not a
 * partial on-conflict-update), so a user's manually-set `status` survives a
 * re-import automatically. This test locks in that existing guarantee; it
 * requires ZERO changes to `Modules/Ledger/Public` or `Modules/Ledger/Internal`
 * (modeled directly on the existing `SplitSurvivesReimportTest.php`).
 */
it('leaves a user-set status unchanged after re-import of the same source row', function (): void {
    $user = User::create(['username' => 'wessel', 'password' => 'opensesame', 'period_start_day' => 1]);

    $account = Account::create([
        'user_id' => $user->id,
        'name' => 'ASN',
        'slug' => 'asn',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);

    $run = ImportRun::create([
        'user_id' => $user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/x.xml',
        'sha256' => str_repeat('a', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    $today = CarbonImmutable::now();

    $canonical = new CanonicalTransaction(
        userId: $user->id,
        accountId: $account->id,
        type: 'expense',
        postedAt: $today,
        bookedAt: $today,
        valueDate: $today,
        amountMinor: -8000,
        currency: 'EUR',
        settledAmountMinor: -8000,
        settledCurrency: 'EUR',
        fxRateUsed: null,
        counterpartyName: 'Albert Heijn',
        counterpartyIban: null,
        counterpartyNormalized: 'albert heijn',
        normalizationVersion: 1,
        description: null,
        categoryId: null,
        sourceFormat: 'camt053',
        importRunId: $run->id,
        sourceRowIndex: 1,
        sourceRef: null,
    );

    // 1. Import the source row (lands with whatever default status the
    // import pipeline stamps — irrelevant here, overwritten in step 2).
    $firstResult = app(RecordTransactions::class)->__invoke([$canonical], $user);
    expect($firstResult->inserted)->toBe(1);

    /** @var Transaction $tx */
    $tx = Transaction::query()->where('user_id', $user->id)->firstOrFail();

    // 2. The user manually sets the status (simulating a toggle/reconcile action).
    app(DatabaseManager::class)->connection()
        ->table('transactions')
        ->where('id', $tx->id)
        ->where('user_id', $user->id)
        ->update(['status' => 'uncleared']);

    // 3. Re-run the SAME import (identical CanonicalTransaction -> identical
    // fingerprint tuple -> insertOrIgnore silently drops it).
    $secondResult = app(RecordTransactions::class)->__invoke([$canonical], $user);
    expect($secondResult->inserted)->toBe(0);
    expect($secondResult->duplicates)->toBe(1);

    // 4. The user-set status survives the re-import byte-for-byte. No
    // duplicate row was created.
    expect(Transaction::query()->where('user_id', $user->id)->count())->toBe(1);
    expect(Transaction::query()->where('id', $tx->id)->value('status'))->toBe('uncleared');
});
