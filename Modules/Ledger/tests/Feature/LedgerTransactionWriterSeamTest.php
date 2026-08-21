<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Actions\ReassignCounterparty;
use Modules\Ledger\Public\Actions\SetTransactionNote;
use Modules\Ledger\Public\Contracts\ReassignsCounterparty;
use Modules\Ledger\Public\Contracts\SetsTransactionNote;

// Both seams are pure guarded writers: they never dispatch events themselves,
// which stays the caller's job, as with UpdateTransactionCategory.

beforeEach(function (): void {
    $this->seedFixtureUserAndAccount();
    $this->asnAccount = Account::query()
        ->where('user_id', $this->fixtureUser->id)
        ->where('iban', 'NL57ASNB0123456789')
        ->firstOrFail();
    $this->run = $this->makeImportRun($this->fixtureUser);
});

it('binds the contracts to their default implementations', function (): void {
    expect($this->app->make(ReassignsCounterparty::class))->toBeInstanceOf(ReassignCounterparty::class);
    expect($this->app->make(SetsTransactionNote::class))->toBeInstanceOf(SetTransactionNote::class);
});

it('ReassignCounterparty — reassigns and returns 1 on a genuine change', function (): void {
    $tx = $this->makeTransaction($this->fixtureUser, $this->asnAccount, $this->run, ['type' => 'expense', 'amount_minor' => -1000]);
    $cpId = (int) DB::table('counterparties')->insertGetId([
        'user_id' => $this->fixtureUser->id,
        'display_name' => 'Albert Heijn',
        'slug' => 'ah-'.bin2hex(random_bytes(4)),
        'type' => 'merchant',
        'created_at' => '2026-06-14 00:00:00',
        'updated_at' => '2026-06-14 00:00:00',
    ]);

    $action = $this->app->make(ReassignsCounterparty::class);
    $affected = $action($tx->id, $cpId, $this->fixtureUser);

    expect($affected)->toBe(1);
    expect((int) DB::table('transactions')->where('id', $tx->id)->value('counterparty_id'))->toBe($cpId);
});

it('ReassignCounterparty — reconciled row returns 0, no write', function (): void {
    $tx = $this->makeTransaction($this->fixtureUser, $this->asnAccount, $this->run, ['type' => 'expense', 'amount_minor' => -1000, 'status' => 'reconciled']);
    $cpId = (int) DB::table('counterparties')->insertGetId([
        'user_id' => $this->fixtureUser->id,
        'display_name' => 'Albert Heijn',
        'slug' => 'ah-'.bin2hex(random_bytes(4)),
        'type' => 'merchant',
        'created_at' => '2026-06-14 00:00:00',
        'updated_at' => '2026-06-14 00:00:00',
    ]);

    $action = $this->app->make(ReassignsCounterparty::class);
    $affected = $action($tx->id, $cpId, $this->fixtureUser);

    expect($affected)->toBe(0);
    expect(DB::table('transactions')->where('id', $tx->id)->value('counterparty_id'))->toBeNull();
});

it('ReassignCounterparty — foreign-owned counterparty returns 0, no write', function (): void {
    $tx = $this->makeTransaction($this->fixtureUser, $this->asnAccount, $this->run, ['type' => 'expense', 'amount_minor' => -1000]);

    $otherUserId = (int) DB::table('users')->insertGetId([
        'username' => 'other-seam-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    $foreignCpId = (int) DB::table('counterparties')->insertGetId([
        'user_id' => $otherUserId,
        'display_name' => 'Other CP',
        'slug' => 'other-cp-'.bin2hex(random_bytes(4)),
        'type' => 'merchant',
        'created_at' => '2026-06-14 00:00:00',
        'updated_at' => '2026-06-14 00:00:00',
    ]);

    $action = $this->app->make(ReassignsCounterparty::class);
    $affected = $action($tx->id, $foreignCpId, $this->fixtureUser);

    expect($affected)->toBe(0);
    expect(DB::table('transactions')->where('id', $tx->id)->value('counterparty_id'))->toBeNull();
});

it('ReassignCounterparty — unchanged value returns 0, no write', function (): void {
    $cpId = (int) DB::table('counterparties')->insertGetId([
        'user_id' => $this->fixtureUser->id,
        'display_name' => 'Albert Heijn',
        'slug' => 'ah-'.bin2hex(random_bytes(4)),
        'type' => 'merchant',
        'created_at' => '2026-06-14 00:00:00',
        'updated_at' => '2026-06-14 00:00:00',
    ]);
    $tx = $this->makeTransaction($this->fixtureUser, $this->asnAccount, $this->run, [
        'type' => 'expense',
        'amount_minor' => -1000,
        'counterparty_id' => $cpId,
    ]);

    $action = $this->app->make(ReassignsCounterparty::class);
    $affected = $action($tx->id, $cpId, $this->fixtureUser);

    expect($affected)->toBe(0);
});

it('SetTransactionNote — mode=set overwrites the note and returns 1', function (): void {
    $tx = $this->makeTransaction($this->fixtureUser, $this->asnAccount, $this->run, ['type' => 'expense', 'amount_minor' => -1000]);

    $action = $this->app->make(SetsTransactionNote::class);
    $affected = $action($tx->id, 'Hello', 'set', $this->fixtureUser);

    expect($affected)->toBe(1);
    expect(DB::table('transactions')->where('id', $tx->id)->value('note'))->toBe('Hello');
});

it('SetTransactionNote — mode=append concatenates onto the existing note', function (): void {
    $tx = $this->makeTransaction($this->fixtureUser, $this->asnAccount, $this->run, ['type' => 'expense', 'amount_minor' => -1000]);
    DB::table('transactions')->where('id', $tx->id)->update(['note' => 'First line']);

    $action = $this->app->make(SetsTransactionNote::class);
    $affected = $action($tx->id, 'Second line', 'append', $this->fixtureUser);

    expect($affected)->toBe(1);
    expect(DB::table('transactions')->where('id', $tx->id)->value('note'))->toBe("First line\nSecond line");
});

it('SetTransactionNote — reconciled row returns 0, no write', function (): void {
    $tx = $this->makeTransaction($this->fixtureUser, $this->asnAccount, $this->run, ['type' => 'expense', 'amount_minor' => -1000, 'status' => 'reconciled']);

    $action = $this->app->make(SetsTransactionNote::class);
    $affected = $action($tx->id, 'Hello', 'set', $this->fixtureUser);

    expect($affected)->toBe(0);
    expect(DB::table('transactions')->where('id', $tx->id)->value('note'))->toBeNull();
});

it('SetTransactionNote — unchanged value returns 0, no write', function (): void {
    $tx = $this->makeTransaction($this->fixtureUser, $this->asnAccount, $this->run, ['type' => 'expense', 'amount_minor' => -1000]);
    DB::table('transactions')->where('id', $tx->id)->update(['note' => 'Same']);

    $action = $this->app->make(SetsTransactionNote::class);
    $affected = $action($tx->id, 'Same', 'set', $this->fixtureUser);

    expect($affected)->toBe(0);
});
