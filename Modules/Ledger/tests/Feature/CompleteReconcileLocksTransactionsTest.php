<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Services\ReconciliationWriter;

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
