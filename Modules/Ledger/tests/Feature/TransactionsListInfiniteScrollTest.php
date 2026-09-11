<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Modules\Ledger\Internal\Http\Livewire\TransactionsList;
use Modules\Ledger\Models\Account;

beforeEach(function (): void {
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-15 12:00:00'));

    /** @var Account $account */
    $account = Account::query()->where('iban', 'NL57ASNB0123456789')->firstOrFail();
    $this->account = $account;
    $this->run = $this->makeImportRun($this->fixtureUser);

    // Ascending dates ending at the frozen clock, so all 130 sit inside
    // recent()'s window -- 100 of them cannot fit on 100 distinct days when the
    // window is 90 wide, so days carry two rows each and the DESC (posted_at,
    // id) cursor breaks the tie on id, which is what it is ordered on.
    for ($i = 0; $i < 130; $i++) {
        $date = CarbonImmutable::parse('2026-06-15')->subDays(intdiv(129 - $i, 2))->toDateString();
        $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
            'amount_minor' => -100,
            'posted_at' => $date,
            'booked_at' => $date.' 12:00:00',
        ]);
    }
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('accumulates rows across sequential loadMore calls', function (): void {
    $component = Livewire::test(TransactionsList::class)
        ->set('currency', 'original');

    $accumulated = $component->get('accumulatedRows');
    expect($accumulated)->toBeArray()->toHaveCount(50);

    expect($component->get('hasMore'))->toBeTrue();
    expect($component->get('nextCursorId'))->not->toBeNull();

    // loadMore reads the server-side cursor from the snapshot — no args passed.
    $component->call('loadMore');

    $accumulated = $component->get('accumulatedRows');
    expect($accumulated)->toHaveCount(100);

    expect($component->get('hasMore'))->toBeTrue();
    expect($component->get('nextCursorId'))->not->toBeNull();

    $component->call('loadMore');

    $accumulated = $component->get('accumulatedRows');
    expect($accumulated)->toHaveCount(130);

    expect($component->get('hasMore'))->toBeFalse();
})->group('phase-4');

it('has no duplicate ids in the accumulated set after all pages loaded', function (): void {
    $component = Livewire::test(TransactionsList::class)
        ->set('currency', 'original');

    $component->call('loadMore');
    $component->call('loadMore');

    /** @var array<array{id: int}> $accumulated */
    $accumulated = $component->get('accumulatedRows');
    $ids = array_column($accumulated, 'id');

    expect(count($ids))->toBe(count(array_unique($ids)));
})->group('phase-4');

it('resets accumulatedRows to a single page after toggleFullHistory', function (): void {
    $component = Livewire::test(TransactionsList::class)
        ->set('currency', 'original');

    $component->call('loadMore');

    expect($component->get('accumulatedRows'))->toHaveCount(100);

    $component->call('toggleFullHistory');

    $accumulated = $component->get('accumulatedRows');
    expect($accumulated)->toHaveCount(50);
})->group('phase-4');

it('resets accumulatedRows to a single page on a fresh mount', function (): void {
    $component = Livewire::test(TransactionsList::class)
        ->set('currency', 'original');

    $accumulated = $component->get('accumulatedRows');
    expect($accumulated)->toHaveCount(50);
})->group('phase-4');
