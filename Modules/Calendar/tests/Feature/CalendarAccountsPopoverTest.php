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
 *   - mount() materializes the defaults into EXPLICIT account-id lists
 *     (CR-01): entries = all owned accounts ON, balance = spendable set —
 *     so the checkboxes render checked in the default state and the first
 *     toggle does what the user intends.
 *   - Toggling "Count in balance" (toggleBalanceAccount) removes/adds the account
 *     from balanceAccountIds without touching visibleAccountIds.
 *   - Toggling "Show entries" (toggleEntriesAccount) removes/adds the account
 *     from visibleAccountIds without touching balanceAccountIds.
 *   - persistAccountPrefs() saves both arrays to user_preferences so a reload
 *     reflects the saved choice — including the explicit "everything off" [].
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

function capAccount(DatabaseManager $db, int $userId, string $name, string $kind = 'bank'): int
{
    $hex = bin2hex(random_bytes(4));

    return $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => $name,
        'slug' => 'cap-'.strtolower($kind).'-'.$hex,
        'kind' => $kind,
        'iban' => 'NL00CAP'.strtoupper($hex),
        'default_currency' => 'EUR',
        'opening_balance_minor' => 0,
        'opening_balance_as_of_date' => '2026-01-01',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-12 00:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

it('materializes defaults on first load: entries all ON, balance = spendable set (CR-01)', function (): void {
    $db = app(DatabaseManager::class);
    $user = capUser('cap-defaults');
    $asnId = capAccount($db, $user->id, 'ASN Checking', 'bank');
    $icsId = capAccount($db, $user->id, 'ICS Card', 'ics_card');

    // Fresh user, no persisted prefs: the component state must hold EXPLICIT
    // ids so the popover checkboxes render checked for the effective accounts.
    Livewire::actingAs($user)
        ->test(CalendarPage::class, ['month' => 6, 'year' => 2026])
        ->assertSet('visibleAccountIds', fn (array $ids): bool => count($ids) === 2 && in_array($asnId, $ids, true) && in_array($icsId, $ids, true))
        ->assertSet('balanceAccountIds', [$asnId]);
});

it('unchecking one account from the default all-on state hides only that account (CR-01 inversion regression)', function (): void {
    $db = app(DatabaseManager::class);
    $user = capUser('cap-uncheck-one');
    $asnId = capAccount($db, $user->id, 'ASN Checking', 'bank');
    $paypalId = capAccount($db, $user->id, 'PayPal', 'paypal');

    // From the materialized default (both ON), unchecking ASN must leave
    // PayPal visible — NOT flip to "only ASN visible" as the inverted
    // pre-fix behavior did.
    Livewire::actingAs($user)
        ->test(CalendarPage::class, ['month' => 6, 'year' => 2026])
        ->call('toggleEntriesAccount', $asnId)
        ->assertSet('visibleAccountIds', [$paypalId]);
});

it('persists the explicit everything-off state and a reload keeps every checkbox off (CR-01)', function (): void {
    $db = app(DatabaseManager::class);
    $user = capUser('cap-all-off');
    $aid = capAccount($db, $user->id, 'ASN Checking', 'bank');

    // Toggle the only account off in both sets, persist.
    Livewire::actingAs($user)
        ->test(CalendarPage::class, ['month' => 6, 'year' => 2026])
        ->call('toggleEntriesAccount', $aid)
        ->call('toggleBalanceAccount', $aid)
        ->assertSet('visibleAccountIds', [])
        ->assertSet('balanceAccountIds', [])
        ->call('persistAccountPrefs');

    // Reload: [] must round-trip as the explicit deselect-all — NOT
    // re-materialize into the defaults.
    Livewire::actingAs($user)
        ->test(CalendarPage::class, ['month' => 6, 'year' => 2026])
        ->assertSet('visibleAccountIds', [])
        ->assertSet('balanceAccountIds', []);
});

it('toggleBalanceAccount removes an account from balanceAccountIds and does not touch visibleAccountIds', function (): void {
    $db = app(DatabaseManager::class);
    $user = capUser('cap-bal-off');
    $aid = capAccount($db, $user->id, 'ASN Checking', 'bank');

    Livewire::actingAs($user)
        ->test(CalendarPage::class, ['month' => 6, 'year' => 2026])
        ->set('balanceAccountIds', [$aid])
        ->set('visibleAccountIds', [$aid])
        ->call('toggleBalanceAccount', $aid)
        ->assertSet('balanceAccountIds', [])
        ->assertSet('visibleAccountIds', [$aid]);  // NOT affected
});

it('toggleBalanceAccount adds an account to balanceAccountIds when it is currently absent', function (): void {
    $db = app(DatabaseManager::class);
    $user = capUser('cap-bal-on');
    $aid = capAccount($db, $user->id, 'ASN Checking 2', 'bank');

    Livewire::actingAs($user)
        ->test(CalendarPage::class, ['month' => 6, 'year' => 2026])
        ->set('balanceAccountIds', [])
        ->call('toggleBalanceAccount', $aid)
        ->assertSet('balanceAccountIds', [$aid]);
});

it('toggleEntriesAccount removes an account from visibleAccountIds and does not touch balanceAccountIds', function (): void {
    $db = app(DatabaseManager::class);
    $user = capUser('cap-entries-off');
    $aid = capAccount($db, $user->id, 'PayPal', 'paypal');

    Livewire::actingAs($user)
        ->test(CalendarPage::class, ['month' => 6, 'year' => 2026])
        ->set('visibleAccountIds', [$aid])
        ->set('balanceAccountIds', [$aid])
        ->call('toggleEntriesAccount', $aid)
        ->assertSet('visibleAccountIds', [])
        ->assertSet('balanceAccountIds', [$aid]);  // NOT affected
});

it('persistAccountPrefs saves choices to user_preferences and a reload reflects them', function (): void {
    $db = app(DatabaseManager::class);
    $user = capUser('cap-persist');
    $aid = capAccount($db, $user->id, 'ICS Card', 'ics');

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

it('persistAccountPrefs strips foreign account ids before writing to user_preferences (WR-07)', function (): void {
    $db = app(DatabaseManager::class);
    $user = capUser('cap-sanitize');
    $otherUser = capUser('cap-sanitize-owner');
    $ownId = capAccount($db, $user->id, 'Own ASN', 'bank');
    $foreignId = capAccount($db, $otherUser->id, 'Foreign ASN', 'bank');

    // Bypass the ownership-validated toggle actions by setting the public
    // properties directly (what a tampered Livewire payload would do).
    Livewire::actingAs($user)
        ->test(CalendarPage::class, ['month' => 6, 'year' => 2026])
        ->set('visibleAccountIds', [$ownId, $foreignId])
        ->set('balanceAccountIds', [$foreignId])
        ->call('persistAccountPrefs')
        ->assertSet('visibleAccountIds', [$ownId])
        ->assertSet('balanceAccountIds', []);

    // The persisted row must contain only owned ids.
    $row = $db->connection()->table('user_preferences')
        ->where('user_id', $user->id)
        ->first(['calendar_entries_accounts', 'calendar_balance_accounts']);
    expect($row)->not->toBeNull();
    expect(json_decode((string) $row->calendar_entries_accounts, true))->toBe([$ownId]);
    expect(json_decode((string) $row->calendar_balance_accounts, true))->toBe([]);
});

it('toggleBalanceAccount silently ignores a foreign account ID', function (): void {
    $db = app(DatabaseManager::class);
    $user = capUser('cap-foreign');
    $otherUser = capUser('cap-foreign-owner');
    $foreignId = capAccount($db, $otherUser->id, 'Foreign Account', 'bank');

    // foreign ID should be silently ignored; balanceAccountIds stays unchanged
    Livewire::actingAs($user)
        ->test(CalendarPage::class, ['month' => 6, 'year' => 2026])
        ->set('balanceAccountIds', [])
        ->call('toggleBalanceAccount', $foreignId)
        ->assertSet('balanceAccountIds', []);
});

it('toggleEntriesAccount silently ignores a foreign account ID', function (): void {
    $db = app(DatabaseManager::class);
    $user = capUser('cap-foreign-entries');
    $otherUser = capUser('cap-foreign-entries-owner');
    $foreignId = capAccount($db, $otherUser->id, 'Foreign Account 2', 'bank');

    Livewire::actingAs($user)
        ->test(CalendarPage::class, ['month' => 6, 'year' => 2026])
        ->set('visibleAccountIds', [])
        ->call('toggleEntriesAccount', $foreignId)
        ->assertSet('visibleAccountIds', []);
});
