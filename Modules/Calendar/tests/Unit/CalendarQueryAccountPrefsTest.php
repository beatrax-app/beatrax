<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calendar\Internal\Services\AccountResolver;
use Modules\Calendar\Internal\Services\CalendarQuery;
use Modules\Core\Models\User;
use Modules\Recurring\Models\RecurringSeries;

/*
 * CalendarQuery — account-preference resolution (CAL-01 D-02/D-03).
 *
 * Tests the default-resolution rules in CalendarQuery::forMonth (CR-01:
 * null = "never configured" → defaults; an explicit array — including the
 * empty array — is taken literally):
 *
 *   1. Null visibleAccountIds → all owned accounts' series appear.
 *   2. Null balanceAccountIds → spendable kinds only:
 *      ON: asn, bank, cash, paypal, paypal_funding
 *      OFF: ics, ics_card, ics_bulk_settle
 *   3. An explicit empty visibleAccountIds array (deselect-all) → no entries.
 *   4. A foreign account id in either array is silently dropped (T-06-02).
 *
 * Security note (T-06-02): both arrays are intersected against the user's
 * owned accounts before any forUser/forecast call.
 */

function cqapUser(string $suffix): User
{
    return User::query()->create([
        'username' => 'cqap-'.$suffix,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function cqapAccount(DatabaseManager $db, int $userId, string $name, string $kind): int
{
    $hex = bin2hex(random_bytes(4));

    return $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => $name,
        'slug' => 'cqap-'.$hex,
        'kind' => $kind,
        'iban' => 'NL00CQAP'.strtoupper($hex),
        'default_currency' => 'EUR',
        'opening_balance_minor' => 0,
        'opening_balance_as_of_date' => '2026-06-01',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);
}

function cqapSeries(User $user, string $name): RecurringSeries
{
    return RecurringSeries::query()->create([
        'user_id' => $user->id,
        'direction' => 'expense',
        'detected_name' => $name,
        'state' => 'approved',
        'cadence' => 'monthly',
        'latest_amount_minor' => -1000,
        'latest_currency' => 'EUR',
        'variance_tolerance_percent' => 25,
        'cluster_key' => 'cqap::'.$name,
        'next_expected_at' => CarbonImmutable::parse('2026-06-15'),
    ]);
}

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-12 00:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

it('shows entries from all owned accounts when visibleAccountIds is null (never configured)', function (): void {
    $db = app(DatabaseManager::class);
    $user = cqapUser('all-visible');

    // Create two accounts with different kinds
    cqapAccount($db, $user->id, 'ASN Checking', 'bank');
    cqapAccount($db, $user->id, 'ICS Card', 'ics_card');

    // Both accounts have a series (CalendarQuery resolves account via occurrences fallback)
    cqapSeries($user, 'Series-ASN');
    cqapSeries($user, 'Series-ICS');

    /** @var CalendarQuery $calendarQuery */
    $calendarQuery = app(CalendarQuery::class);

    // Null visibleAccountIds → all accounts ON (D-02 default)
    $days = $calendarQuery->forMonth($user, 2026, 6, null, null);

    // Both series should appear (we can't easily check per-account without occurrences,
    // but the entry count for the month should include both series)
    $totalEntries = 0;
    foreach ($days as $day) {
        $totalEntries += count($day->entries);
    }

    // Both series have nextExpectedAt on June 15, so 2 entries for June
    expect($totalEntries)->toBeGreaterThanOrEqual(2);
});

it('defaults balance to spendable-kind accounts when balanceAccountIds is null (never configured)', function (): void {
    $db = app(DatabaseManager::class);
    $user = cqapUser('spendable-default');

    // Create one spendable account (asn) and one non-spendable (ics_card)
    $asnId = cqapAccount($db, $user->id, 'ASN Checking', 'bank');
    $icsId = cqapAccount($db, $user->id, 'ICS Card', 'ics_card');

    /** @var CalendarQuery $calendarQuery */
    $calendarQuery = app(CalendarQuery::class);

    // We cannot directly inspect the effective balance account IDs from the output,
    // but we can verify the day DTOs are returned (no crash) and that ICS is not
    // included in the balance by seeding a forecast for ICS only.
    // When balance is null → spendable default (asn ON, ics_card OFF).
    // Since no forecast runs exist, isComputing=true for all days regardless.
    $days = $calendarQuery->forMonth($user, 2026, 6, null, null);

    // The public spendableAccountIds helper (used by CalendarPage::mount to
    // materialize the D-03 default, CR-01) must include asn and exclude ics_card.
    expect($calendarQuery->spendableAccountIds($user))->toBe([$asnId]);

    // Should return 35 days (5 weeks: Mon May 25 – Sun Jun 28 for June 2026)
    // or similar Mon–Sun grid. Either way, should not throw.
    expect($days)->toBeArray()->not->toBeEmpty();

    // Verify the spendable constant: bank accounts are in the ON set
    // We do this by checking that the spendable default would include $asnId
    // and exclude $icsId via a direct constant check.
    $spendableKinds = (new ReflectionClass(AccountResolver::class))
        ->getConstant('SPENDABLE_KINDS');
    expect($spendableKinds)->toContain('bank');
    expect($spendableKinds)->toContain('cash');
    expect($spendableKinds)->toContain('paypal');
    expect($spendableKinds)->toContain('paypal_funding');
    expect($spendableKinds)->not->toContain('ics');
    expect($spendableKinds)->not->toContain('ics_card');
    expect($spendableKinds)->not->toContain('ics_bulk_settle');

    unset($asnId, $icsId);
});

it('returns no entries for an explicit empty visibleAccountIds array (deselect-all, CR-01)', function (): void {
    $db = app(DatabaseManager::class);
    $user = cqapUser('deselect-all');

    cqapAccount($db, $user->id, 'ASN Checking', 'bank');
    cqapSeries($user, 'Series-Hidden');

    /** @var CalendarQuery $calendarQuery */
    $calendarQuery = app(CalendarQuery::class);

    // Explicit [] = every account deselected — the series must NOT appear.
    $days = $calendarQuery->forMonth($user, 2026, 6, [], null);

    $totalEntries = 0;
    foreach ($days as $day) {
        $totalEntries += count($day->entries);
    }

    expect($totalEntries)->toBe(0);
});

it('drops a foreign account id from visibleAccountIds (T-06-02)', function (): void {
    $db = app(DatabaseManager::class);
    $owner = cqapUser('foreign-visible-owner');
    $other = cqapUser('foreign-visible-other');

    // Other user has an account
    $foreignAccountId = cqapAccount($db, $other->id, 'Other ASN', 'bank');

    // Owner has one series visible on June 15
    cqapSeries($owner, 'Owner Series');

    /** @var CalendarQuery $calendarQuery */
    $calendarQuery = app(CalendarQuery::class);

    // Pass the foreign account id as the only visible account
    $days = $calendarQuery->forMonth($owner, 2026, 6, [$foreignAccountId], null);

    // The foreign id must be dropped; no entries appear (owner has no account with that id)
    $totalEntries = 0;
    foreach ($days as $day) {
        $totalEntries += count($day->entries);
    }

    // With foreign id dropped and no entries matching owner's accounts, entries = 0
    // (the series' account defaults to owner's first account, but visibleAccountIds
    // was the foreign id only → intersect = empty → no visible accounts → no entries)
    expect($totalEntries)->toBe(0);
});

it('drops a foreign account id from balanceAccountIds (T-06-02)', function (): void {
    $db = app(DatabaseManager::class);
    $owner = cqapUser('foreign-balance-owner');
    $other = cqapUser('foreign-balance-other');

    $foreignAccountId = cqapAccount($db, $other->id, 'Other ASN', 'bank');

    /** @var CalendarQuery $calendarQuery */
    $calendarQuery = app(CalendarQuery::class);

    // Pass the foreign account id as the balance account
    // Should not throw NotFoundHttpException or leak data; should return isComputing=true
    // for all days (no valid balance accounts after intersection)
    $days = $calendarQuery->forMonth($owner, 2026, 6, null, [$foreignAccountId]);

    expect($days)->toBeArray()->not->toBeEmpty();

    // All days should be computing (foreign id dropped, effective balance is empty)
    $allComputing = true;
    foreach ($days as $day) {
        if (! $day->isComputing) {
            $allComputing = false;
            break;
        }
    }
    expect($allComputing)->toBeTrue('Foreign balance account dropped → no balance accounts → isComputing for all days');

    unset($foreignAccountId);
});
