<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Calendar\Internal\Http\Livewire\CalendarPage;
use Modules\Core\Models\User;

/*
 * CalendarPage — Accounts popover: two independent controls + persistence (D-02, D-03).
 *
 * Contract:
 *   - Toggling "Count in balance" (toggleBalanceAccount) removes/adds the account
 *     from balanceAccountIds without touching visibleAccountIds.
 *   - Toggling "Show entries" (toggleEntriesAccount) removes/adds the account
 *     from visibleAccountIds without touching balanceAccountIds.
 *   - persistAccountPrefs() saves both arrays to user_preferences so a reload
 *     reflects the saved choice.
 *   - A foreign account ID is silently ignored by both toggle actions.
 */

function capUser(string $suffix = 'cap'): User
{
    return User::query()->create([
        'username' => $suffix,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function capAccount(DatabaseManager $db, int $userId, string $name, string $kind = 'asn'): int
{
    $hex = bin2hex(random_bytes(4));

    return $db->connection()->table('accounts')->insertGetId([
        'user_id'          => $userId,
        'name'             => $name,
        'slug'             => 'cap-'.strtolower($kind).'-'.$hex,
        'kind'             => $kind,
        'iban'             => 'NL00CAP'.strtoupper($hex),
        'default_currency' => 'EUR',
        'opening_balance_minor' => 0,
        'opening_balance_as_of_date' => '2026-01-01',
        'created_at'       => '2026-01-01 00:00:00',
        'updated_at'       => '2026-01-01 00:00:00',
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-12 00:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

it('toggleBalanceAccount removes an account from balanceAccountIds and does not touch visibleAccountIds', function (): void {
    $db   = app(DatabaseManager::class);
    $user = capUser('cap-bal-off');
    $aid  = capAccount($db, $user->id, 'ASN Checking', 'asn');

    Livewire::actingAs($user)
        ->test(CalendarPage::class, ['month' => 6, 'year' => 2026])
        ->set('balanceAccountIds', [$aid])
        ->set('visibleAccountIds', [$aid])
        ->call('toggleBalanceAccount', $aid)
        ->assertSet('balanceAccountIds', [])
        ->assertSet('visibleAccountIds', [$aid]);  // NOT affected
});

it('toggleBalanceAccount adds an account to balanceAccountIds when it is currently absent', function (): void {
    $db   = app(DatabaseManager::class);
    $user = capUser('cap-bal-on');
    $aid  = capAccount($db, $user->id, 'ASN Checking 2', 'asn');

    Livewire::actingAs($user)
        ->test(CalendarPage::class, ['month' => 6, 'year' => 2026])
        ->set('balanceAccountIds', [])
        ->call('toggleBalanceAccount', $aid)
        ->assertSet('balanceAccountIds', [$aid]);
});

it('toggleEntriesAccount removes an account from visibleAccountIds and does not touch balanceAccountIds', function (): void {
    $db   = app(DatabaseManager::class);
    $user = capUser('cap-entries-off');
    $aid  = capAccount($db, $user->id, 'PayPal', 'paypal');

    Livewire::actingAs($user)
        ->test(CalendarPage::class, ['month' => 6, 'year' => 2026])
        ->set('visibleAccountIds', [$aid])
        ->set('balanceAccountIds', [$aid])
        ->call('toggleEntriesAccount', $aid)
        ->assertSet('visibleAccountIds', [])
        ->assertSet('balanceAccountIds', [$aid]);  // NOT affected
});

it('persistAccountPrefs saves choices to user_preferences and a reload reflects them', function (): void {
    $db   = app(DatabaseManager::class);
    $user = capUser('cap-persist');
    $aid  = capAccount($db, $user->id, 'ICS Card', 'ics');

    // Toggle balance off, persist, then reload and verify
    Livewire::actingAs($user)
        ->test(CalendarPage::class, ['month' => 6, 'year' => 2026])
        ->set('balanceAccountIds', [$aid])
        ->call('persistAccountPrefs');

    // Reload: mount() should load the saved preference
    Livewire::actingAs($user)
        ->test(CalendarPage::class, ['month' => 6, 'year' => 2026])
        ->assertSet('balanceAccountIds', [$aid]);
});

it('toggleBalanceAccount silently ignores a foreign account ID', function (): void {
    $db   = app(DatabaseManager::class);
    $user = capUser('cap-foreign');
    $otherUser = capUser('cap-foreign-owner');
    $foreignId = capAccount($db, $otherUser->id, 'Foreign Account', 'asn');

    // foreign ID should be silently ignored; balanceAccountIds stays unchanged
    Livewire::actingAs($user)
        ->test(CalendarPage::class, ['month' => 6, 'year' => 2026])
        ->set('balanceAccountIds', [])
        ->call('toggleBalanceAccount', $foreignId)
        ->assertSet('balanceAccountIds', []);
});

it('toggleEntriesAccount silently ignores a foreign account ID', function (): void {
    $db        = app(DatabaseManager::class);
    $user      = capUser('cap-foreign-entries');
    $otherUser = capUser('cap-foreign-entries-owner');
    $foreignId = capAccount($db, $otherUser->id, 'Foreign Account 2', 'asn');

    Livewire::actingAs($user)
        ->test(CalendarPage::class, ['month' => 6, 'year' => 2026])
        ->set('visibleAccountIds', [])
        ->call('toggleEntriesAccount', $foreignId)
        ->assertSet('visibleAccountIds', []);
});
