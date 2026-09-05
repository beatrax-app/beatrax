<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Enums\ClearedStatus;
use Modules\Ledger\Public\Services\TransactionStatusWriter;
use Modules\Sync\Public\Events\TransactionMutated;

// Three places wrote this column and none delegated, so the edit-lock a
// reconciled row depends on was only ever as strong as whichever writer a
// caller happened to reach. The graph is what makes the column checkable:
// two devices can each take a legal step and land on a pair that is not one.
//
// Every refusal below is a silence, so every refusal below is followed by a
// legal step through the same instance. A writer built before Event::fake()
// keeps the real dispatcher, and then a refusal that never happened reads
// exactly like one that did.

beforeEach(function (): void {
    $this->seedFixtureUserAndAccount();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-14 12:00:00'));

    $this->account = Account::query()
        ->where('user_id', $this->fixtureUser->id)
        ->where('iban', 'NL57ASNB0123456789')
        ->firstOrFail();

    $this->run = $this->makeImportRun($this->fixtureUser);

    // Arming the fake and building the writer in one call is the whole reason
    // this closure exists: the order is the thing these cases get wrong.
    $this->listeningWriter = function (): TransactionStatusWriter {
        Event::fake([TransactionMutated::class]);

        return app(TransactionStatusWriter::class);
    };

    $this->statusOf = fn (int $id): string => (string) DB::table('transactions')->where('id', $id)->value('status');

    $this->rowWith = function (string $status): int {
        $tx = $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
            'posted_at' => '2026-06-14',
            'booked_at' => '2026-06-14 12:00:00',
        ]);

        DB::table('transactions')->where('id', $tx->id)->update(['status' => $status]);

        return (int) $tx->id;
    };
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('refuses to toggle a reconciled row cleared', function (): void {
    $id = ($this->rowWith)(ClearedStatus::Reconciled->value);
    $writer = ($this->listeningWriter)();

    $writer->toggleCleared($this->fixtureUser, $id);

    expect(($this->statusOf)($id))->toBe(ClearedStatus::Reconciled->value);
    Event::assertNotDispatched(TransactionMutated::class);

    $writer->unreconcile($this->fixtureUser, $id);

    expect(($this->statusOf)($id))->toBe(ClearedStatus::Cleared->value);
    Event::assertDispatchedTimes(TransactionMutated::class, 1);
});

// `cleared` is the case that matters and the only one the graph would let
// through on its own: reconciled -> cleared is a legal edge, so a source
// saying "cleared" would walk the lock off the row if this refusal were not
// here. `uncleared` is checked beside it because a refusal that only holds
// for the edge the graph already refuses is not a refusal.
it('refuses to restate a reconciled row from an importing source', function (): void {
    $id = ($this->rowWith)(ClearedStatus::Reconciled->value);
    $writer = ($this->listeningWriter)();

    expect($writer->restateFromSource($this->fixtureUser, $id, ClearedStatus::Cleared))->toBeFalse()
        ->and($writer->restateFromSource($this->fixtureUser, $id, ClearedStatus::Uncleared))->toBeFalse()
        ->and(($this->statusOf)($id))->toBe(ClearedStatus::Reconciled->value);
    Event::assertNotDispatched(TransactionMutated::class);

    $writer->unreconcile($this->fixtureUser, $id);

    expect($writer->restateFromSource($this->fixtureUser, $id, ClearedStatus::Uncleared))->toBeTrue()
        ->and(($this->statusOf)($id))->toBe(ClearedStatus::Uncleared->value);
    Event::assertDispatchedTimes(TransactionMutated::class, 2);
});

it('refuses a jump to reconciled that skips the cleared step', function (): void {
    $id = ($this->rowWith)(ClearedStatus::Uncleared->value);
    $writer = ($this->listeningWriter)();

    $adopted = $writer->restateFromSource($this->fixtureUser, $id, ClearedStatus::Reconciled);

    expect($adopted)->toBeFalse()
        ->and(($this->statusOf)($id))->toBe(ClearedStatus::Uncleared->value);
    Event::assertNotDispatched(TransactionMutated::class);

    expect($writer->restateFromSource($this->fixtureUser, $id, ClearedStatus::Cleared))->toBeTrue()
        ->and(($this->statusOf)($id))->toBe(ClearedStatus::Cleared->value);
    Event::assertDispatchedTimes(TransactionMutated::class, 1);
});

it('leaves a row alone when un-reconcile names one that is not reconciled', function (): void {
    $id = ($this->rowWith)(ClearedStatus::Uncleared->value);
    $writer = ($this->listeningWriter)();

    $writer->unreconcile($this->fixtureUser, $id);

    expect(($this->statusOf)($id))->toBe(ClearedStatus::Uncleared->value);
    Event::assertNotDispatched(TransactionMutated::class);

    $writer->toggleCleared($this->fixtureUser, $id);

    expect(($this->statusOf)($id))->toBe(ClearedStatus::Cleared->value);
    Event::assertDispatchedTimes(TransactionMutated::class, 1);
});

it('announces every transition it does take, once', function (): void {
    $id = ($this->rowWith)(ClearedStatus::Reconciled->value);
    $writer = ($this->listeningWriter)();

    $writer->unreconcile($this->fixtureUser, $id);
    $writer->toggleCleared($this->fixtureUser, $id);

    expect(($this->statusOf)($id))->toBe(ClearedStatus::Uncleared->value);

    Event::assertDispatchedTimes(TransactionMutated::class, 2);
    Event::assertDispatched(
        TransactionMutated::class,
        fn (TransactionMutated $e): bool => ($e->dirtyFields['status'] ?? null) === ClearedStatus::Cleared->value,
    );
    Event::assertDispatched(
        TransactionMutated::class,
        fn (TransactionMutated $e): bool => ($e->dirtyFields['status'] ?? null) === ClearedStatus::Uncleared->value,
    );
});

it('writes nothing for a transaction another user owns', function (): void {
    $id = ($this->rowWith)(ClearedStatus::Cleared->value);
    $writer = ($this->listeningWriter)();

    $stranger = User::create([
        'username' => 'cleared-status-stranger',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);

    $writer->toggleCleared($stranger, $id);
    $writer->restateFromSource($stranger, $id, ClearedStatus::Uncleared);

    expect(($this->statusOf)($id))->toBe(ClearedStatus::Cleared->value);
    Event::assertNotDispatched(TransactionMutated::class);

    $writer->toggleCleared($this->fixtureUser, $id);

    expect(($this->statusOf)($id))->toBe(ClearedStatus::Uncleared->value);
    Event::assertDispatchedTimes(TransactionMutated::class, 1);
});

it('describes a state graph with no edge from uncleared to reconciled', function (): void {
    expect(ClearedStatus::Uncleared->allowedNext())->toBe([ClearedStatus::Cleared])
        ->and(ClearedStatus::Cleared->allowedNext())->toBe([ClearedStatus::Uncleared, ClearedStatus::Reconciled])
        ->and(ClearedStatus::Reconciled->allowedNext())->toBe([ClearedStatus::Cleared])
        ->and(ClearedStatus::Uncleared->canTransitionTo(ClearedStatus::Reconciled))->toBeFalse();
});
