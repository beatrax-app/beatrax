<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Services\ReconciliationWriter;
use Modules\Sync\Public\Events\TransactionMutated;

/*
 * Wave 0 RED stub (D-08, GREEN in 13.3-04).
 * `Modules/Ledger/Public/Services/ReconciliationWriter.php` does not exist
 * yet. Per 13.3-PATTERNS.md, `completeReconcile(User, accountId,
 * statementDate)` bulk-transitions this account's `cleared` transactions
 * posted on or before the statement date to `reconciled`; `uncleared` rows
 * and rows posted AFTER the statement date are left untouched. Scoped by
 * `user_id` (I2 guard) throughout.
 */

beforeEach(function (): void {
    $this->user = User::create(['username' => 'complete-reconcile-fixture', 'password' => 'fixture-password', 'period_start_day' => 1]);
    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN',
        'slug' => 'asn-complete-reconcile-fixture',
        'kind' => 'asn',
        'iban' => 'NL57ASNB0000000005',
        'default_currency' => 'EUR',
    ]);
    $this->run = $this->makeImportRun($this->user);
});

it('transitions cleared transactions up to the statement date to reconciled, leaves uncleared and future rows untouched', function (): void {
    $inWindowCleared = $this->makeTransaction($this->user, $this->account, $this->run, ['status' => 'cleared', 'posted_at' => '2026-06-10']);
    $inWindowUncleared = $this->makeTransaction($this->user, $this->account, $this->run, ['status' => 'uncleared', 'posted_at' => '2026-06-11']);
    $afterWindowCleared = $this->makeTransaction($this->user, $this->account, $this->run, ['status' => 'cleared', 'posted_at' => '2026-06-20']);

    app(ReconciliationWriter::class)->completeReconcile($this->user, $this->account->id, CarbonImmutable::parse('2026-06-15'));

    expect(DB::table('transactions')->where('id', $inWindowCleared->id)->value('status'))->toBe('reconciled');
    expect(DB::table('transactions')->where('id', $inWindowUncleared->id)->value('status'))->toBe('uncleared');
    expect(DB::table('transactions')->where('id', $afterWindowCleared->id)->value('status'))->toBe('cleared');
});

it('is scoped by user_id — never transitions another user\'s transactions', function (): void {
    $otherUser = User::create(['username' => 'complete-reconcile-other', 'password' => 'fixture-password', 'period_start_day' => 1]);
    $otherAccount = Account::create([
        'user_id' => $otherUser->id,
        'name' => 'ASN other',
        'slug' => 'asn-complete-reconcile-other',
        'kind' => 'asn',
        'iban' => 'NL57ASNB0000000006',
        'default_currency' => 'EUR',
    ]);
    $otherRun = $this->makeImportRun($otherUser);
    $otherTx = $this->makeTransaction($otherUser, $otherAccount, $otherRun, ['status' => 'cleared', 'posted_at' => '2026-06-10']);

    app(ReconciliationWriter::class)->completeReconcile($this->user, $this->account->id, CarbonImmutable::parse('2026-06-15'));

    expect(DB::table('transactions')->where('id', $otherTx->id)->value('status'))->toBe('cleared');
});

it('dispatches a reconciled event only for rows the update actually transitioned (WR-02)', function (): void {
    Event::fake([TransactionMutated::class]);

    $inWindowA = $this->makeTransaction($this->user, $this->account, $this->run, ['status' => 'cleared', 'posted_at' => '2026-06-10']);
    $inWindowB = $this->makeTransaction($this->user, $this->account, $this->run, ['status' => 'cleared', 'posted_at' => '2026-06-12']);
    // An uncleared in-window row and a cleared row posted after the window:
    // neither is transitioned, so neither may receive a reconciled event.
    $uncleared = $this->makeTransaction($this->user, $this->account, $this->run, ['status' => 'uncleared', 'posted_at' => '2026-06-11']);
    $afterWindow = $this->makeTransaction($this->user, $this->account, $this->run, ['status' => 'cleared', 'posted_at' => '2026-06-20']);

    app(ReconciliationWriter::class)->completeReconcile($this->user, $this->account->id, CarbonImmutable::parse('2026-06-15'));

    Event::assertDispatchedTimes(TransactionMutated::class, 2);

    foreach ([$inWindowA->id, $inWindowB->id] as $id) {
        Event::assertDispatched(
            TransactionMutated::class,
            fn (TransactionMutated $e): bool => $e->transactionId === $id
                && ($e->dirtyFields['status'] ?? null) === 'reconciled',
        );
    }

    Event::assertNotDispatched(
        TransactionMutated::class,
        fn (TransactionMutated $e): bool => in_array($e->transactionId, [$uncleared->id, $afterWindow->id], true),
    );
});

it('a second reconcile under a frozen clock counts and dispatches only the newly transitioned rows, never rows a prior reconcile already locked', function (): void {
    // Regression for the WR-04/WR-02 timestamp re-select bug: when two
    // completeReconcile() calls land in the same wall-clock second (or the
    // clock is frozen, as under a Clock double), re-selecting "status =
    // reconciled AND updated_at = $reconciledAt" cannot tell this call's
    // rows apart from a prior call's rows sharing the same stamp. Freezing
    // CarbonImmutable::setTestNow() reproduces exactly that same-timestamp
    // collision deterministically.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-20 09:00:00'));

    Event::fake([TransactionMutated::class]);

    $r1 = $this->makeTransaction($this->user, $this->account, $this->run, ['status' => 'cleared', 'posted_at' => '2026-06-10']);

    $writer = app(ReconciliationWriter::class);

    $firstCount = $writer->completeReconcile($this->user, $this->account->id, CarbonImmutable::parse('2026-06-15'));
    expect($firstCount)->toBe(1);
    expect(DB::table('transactions')->where('id', $r1->id)->value('status'))->toBe('reconciled');

    // A new cleared row appears (e.g. cleared between statements) and gets
    // reconciled by a second call — still under the same frozen instant.
    $r2 = $this->makeTransaction($this->user, $this->account, $this->run, ['status' => 'cleared', 'posted_at' => '2026-06-16']);

    $secondCount = $writer->completeReconcile($this->user, $this->account->id, CarbonImmutable::parse('2026-06-20'));

    expect($secondCount)->toBe(1);
    expect(DB::table('transactions')->where('id', $r2->id)->value('status'))->toBe('reconciled');

    Event::assertDispatchedTimes(TransactionMutated::class, 2);

    /** @var array<int, int> $dispatchedIds */
    $dispatchedIds = collect(Event::dispatched(TransactionMutated::class))
        ->map(static fn (array $args): int => $args[0]->transactionId)
        ->all();

    // Exactly one event per row, ever — the bug this guards against is the
    // second call re-selecting R1 (same `updated_at` stamp as R2) and
    // dispatching a spurious second `reconciled` event for it.
    expect(array_count_values($dispatchedIds))->toBe([
        $r1->id => 1,
        $r2->id => 1,
    ]);

    CarbonImmutable::setTestNow();
});
