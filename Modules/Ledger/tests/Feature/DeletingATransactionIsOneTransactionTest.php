<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Contracts\DeletesTransaction;
use Modules\Search\Public\Contracts\SearchIndexWriterContract;
use Modules\Sync\Public\Events\TransactionMutated;

beforeEach(function (): void {
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-15 12:00:00'));

    // The Sync capture listener wants an OpLogWriter with runtime device creds
    // that nothing binds here, so the event is faked rather than handled.
    Event::fake([TransactionMutated::class]);

    /** @var Account $asn */
    $asn = Account::query()
        ->where('user_id', $this->fixtureUser->id)
        ->where('iban', 'NL57ASNB0123456789')
        ->firstOrFail();
    $this->asnAccount = $asn;

    /** @var Account $ics */
    $ics = Account::query()
        ->where('user_id', $this->fixtureUser->id)
        ->where('iban', 'ICS-CARD')
        ->firstOrFail();
    $this->icsAccount = $ics;

    $this->run = $this->makeImportRun($this->fixtureUser);

    $this->makePair = function (): array {
        $out = $this->makeTransaction($this->fixtureUser, $this->asnAccount, $this->run, [
            'type' => 'transfer_out',
            'amount_minor' => -10000,
            'settled_amount_minor' => -10000,
            'posted_at' => '2026-05-10',
            'booked_at' => '2026-05-10 12:00:00',
            'counterparty_name' => 'Settlement to ICS',
        ]);

        $in = $this->makeTransaction($this->fixtureUser, $this->icsAccount, $this->run, [
            'type' => 'transfer_in',
            'amount_minor' => 10000,
            'settled_amount_minor' => 10000,
            'posted_at' => '2026-05-10',
            'booked_at' => '2026-05-10 12:00:00',
            'counterparty_name' => 'Settlement from ASN',
        ]);

        DB::table('transactions')->where('id', $out->id)->update(['pair_transaction_id' => $in->id]);
        DB::table('transactions')->where('id', $in->id)->update(['pair_transaction_id' => $out->id]);

        return [$out->id, $in->id];
    };
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('retypes the survivor when one leg of a transfer pair is deleted', function (): void {
    [$outId, $inId] = ($this->makePair)();

    app(DeletesTransaction::class)->delete($this->fixtureUser, $outId);

    $survivor = DB::table('transactions')->where('id', $inId)->first(['type', 'pair_transaction_id']);

    expect($survivor->type)->toBe('income')
        ->and($survivor->pair_transaction_id)->toBeNull();
});

it('retypes the survivor to expense when the incoming leg is the one deleted', function (): void {
    [$outId, $inId] = ($this->makePair)();

    app(DeletesTransaction::class)->delete($this->fixtureUser, $inId);

    expect(DB::table('transactions')->where('id', $outId)->value('type'))->toBe('expense');
});

it('leaves the row, its legs and its pair alone when a step after the parent delete throws', function (): void {
    [$outId, $inId] = ($this->makePair)();

    $categoryId = DB::table('categories')->insertGetId([
        'user_id' => $this->fixtureUser->id,
        'name' => 'Delete atomicity fixture',
        'slug' => 'delete-atomicity-fixture',
        'kind' => 'expense',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('transaction_splits')->insert([
        'user_id' => $this->fixtureUser->id,
        'transaction_id' => $outId,
        'category_id' => $categoryId,
        'settled_amount_minor' => -10000,
        'settled_currency' => 'EUR',
        'note' => null,
        'sort_order' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->app->instance(SearchIndexWriterContract::class, new class implements SearchIndexWriterContract
    {
        public function upsertForTransaction(int $transactionId, int $actorUserId): void {}

        public function deleteForTransaction(int $transactionId, int $actorUserId): void
        {
            throw new RuntimeException('search index unavailable');
        }
    });

    try {
        app(DeletesTransaction::class)->delete($this->fixtureUser, $outId);
    } catch (RuntimeException) {
        // The failure is the point; what matters is what survived it.
    }

    expect(DB::table('transactions')->where('id', $outId)->exists())->toBeTrue()
        ->and(DB::table('transaction_splits')->where('transaction_id', $outId)->count())->toBe(1)
        ->and(DB::table('transactions')->where('id', $inId)->value('type'))->toBe('transfer_in');
});
