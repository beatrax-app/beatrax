<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Models\TransactionSplit;
use Modules\Ledger\Public\Actions\RecordTransactions;
use Modules\Ledger\Public\Actions\SaveTransactionSplit;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

uses(RefreshDatabase::class);

/**
 * Req 9 / D-10: re-importing the same source row must never create,
 * duplicate, or wipe a previously-split transaction's legs.
 *
 * Per RESEARCH.md "Idempotency" finding, no new production code is required
 * — `RecordTransactions` dedupes via `insertOrIgnore` on the fingerprint
 * UNIQUE index, silently dropping the duplicate, and never touches
 * `transaction_splits` (a wholly separate table). This test proves that
 * structural safety rather than guarding against a real defect.
 */
it('leaves a split transaction leg count, amounts, and leg ids unchanged after re-import of the same source row', function (): void {
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

    $groceries = Category::create(['user_id' => null, 'name' => 'Groceries', 'slug' => 'groceries', 'kind' => 'expense', 'display_order' => 1]);
    $household = Category::create(['user_id' => null, 'name' => 'Household', 'slug' => 'household', 'kind' => 'expense', 'display_order' => 2]);

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

    // 1. Import the source row.
    $firstResult = app(RecordTransactions::class)->__invoke([$canonical], $user);
    expect($firstResult->inserted)->toBe(1);

    /** @var Transaction $tx */
    $tx = Transaction::query()->where('user_id', $user->id)->firstOrFail();

    // 2. Split it into 2 legs.
    app(SaveTransactionSplit::class)->save($user, $tx->id, [
        ['id' => null, 'category_id' => $groceries->id, 'settled_amount_minor' => -6000, 'note' => 'weekly shop'],
        ['id' => null, 'category_id' => $household->id, 'settled_amount_minor' => -2000, 'note' => null],
    ]);

    $legsBefore = TransactionSplit::query()->where('transaction_id', $tx->id)->orderBy('id')->get();
    expect($legsBefore)->toHaveCount(2);
    $legIdsBefore = $legsBefore->pluck('id')->all();
    $legAmountsBefore = $legsBefore->pluck('settled_amount_minor')->all();
    $legCategoriesBefore = $legsBefore->pluck('category_id')->all();

    // 3. Re-run the SAME import (identical CanonicalTransaction → identical
    // fingerprint tuple → insertOrIgnore silently drops it).
    $secondResult = app(RecordTransactions::class)->__invoke([$canonical], $user);
    expect($secondResult->inserted)->toBe(0);
    expect($secondResult->duplicates)->toBe(1);

    // 4. Assert legs are byte-for-byte unchanged: same count, same ids,
    // same amounts/categories. No duplicate transaction row.
    expect(Transaction::query()->where('user_id', $user->id)->count())->toBe(1);

    $legsAfter = TransactionSplit::query()->where('transaction_id', $tx->id)->orderBy('id')->get();
    expect($legsAfter)->toHaveCount(2);
    expect($legsAfter->pluck('id')->all())->toBe($legIdsBefore);
    expect($legsAfter->pluck('settled_amount_minor')->all())->toBe($legAmountsBefore);
    expect($legsAfter->pluck('category_id')->all())->toBe($legCategoriesBefore);
});
